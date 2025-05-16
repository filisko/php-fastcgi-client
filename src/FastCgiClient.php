<?php
declare(strict_types=1);

namespace Filisko;

use Filisko\Functions;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use InvalidArgumentException;
use Middlewares\Utils\Factory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * FastCGI Client for PHP
 *
 * This class implements a fully compliant FastCGI (version 1) client,
 * allowing you to send PSR-7 HTTP requests directly to FastCGI applications
 * such as PHP-FPM over either TCP or Unix sockets.
 *
 * 🚀 Key features:
 * - Supports request multiplexing (multiple concurrent requests).
 * - Promise-based API using Guzzle Promises for asynchronous workflows.
 * - Full protocol coverage: BEGIN_REQUEST, PARAMS, STDIN, STDOUT, STDERR, END_REQUEST.
 * - Handles padding, socket errors, and timeouts gracefully.
 * - Automatically converts FastCGI responses into PSR-7 Responses.
 * - Fully testable: native socket functions are injectable and mockable.
 *
 * 💡 Common use cases:
 * - Talking directly to PHP-FPM without an HTTP server (e.g., from a custom gateway).
 * - Building custom HTTP or RPC gateways that execute PHP scripts via FastCGI.
 * - Benchmarking or testing PHP-FPM pools under load.
 *
 * 📄 Protocol reference:
 * @see https://fastcgi-archives.github.io/FastCGI_Specification.html
 *
 * 🧠 Architectural notes:
 * - A FastCGI request consists of multiple records, not a single packet.
 * - Each record has an 8-byte header, followed by optional content and optional padding.
 * - The client reads and assembles STDOUT and STDERR until an END_REQUEST record is received.
 *
 * Minimal example:
 *
 * $client = new Client('/var/run/php/php-fpm.sock');
 * $response = $client->sendAsync($psrRequest)->wait();
 *
 * echo $response->getStatusCode();
 * echo (string) $response->getBody();
 */
class FastCgiClient implements ClientInterface
{
    /**
     * Value for version of FCGI Header
     */
    private const VERSION_1 = 1;

    /**
     * See the FastCGI specification for Types and Constants.
     *
     * @see https://fastcgi-archives.github.io/FastCGI_Specification.html#S8
     *
     * Even though not all defined constants are used in this class,
     * we decided to keep them for documentation purposes of the protocol.
     */
    private const TYPE_BEGIN_REQUEST     = 1;
    private const TYPE_ABORT_REQUEST     = 2;
    private const TYPE_END_REQUEST       = 3;
    private const TYPE_PARAMS            = 4;
    private const TYPE_STDIN             = 5;
    private const TYPE_STDOUT            = 6;
    private const TYPE_STDERR            = 7;
    private const TYPE_DATA              = 8;
    private const TYPE_GET_VALUES        = 9;
    private const TYPE_GET_VALUES_RESULT = 10;
    private const TYPE_UNKNOWN_TYPE      = 11;
    private const TYPE_MAXTYPE           = self::TYPE_UNKNOWN_TYPE;

    /**
     * Values for role component of FCGI BeginRequestBody
     */
    private const RESPONDER  = 1;
    private const AUTHORIZER = 2;
    private const FILTER     = 3;

    /**
     * Values for protocolStatus component of FCGI EndRequestBody
     */
    private const REQUEST_COMPLETE        = 0;
    private const STATUS_CANT_MPX_CONN    = 1;
    private const STATUS_OVERLOADED       = 2;
    private const STATUS_UNKNOWN_ROLE     = 3;

    /**
     * Value for a version component of FCGI Header
     */
    private const MAX_CONNS  = 'MAX_CONNS';
    private const MAX_REQS   = 'MAX_REQS';
    private const MPXS_CONNS = 'MPXS_CONNS';

    /**
     * Number of bytes used in a FastCGI header record
     */
    private const HEADER_LENGTH = 8;

    /**
     * Proxy for PHP functions. This allows native functions to be tested with PHPUnit.
     *
     * @var Functions
     */
    protected $functions;

    /**
     * FastCGI's server Host.
     *
     * @var string
     */
    protected $host;

    /**
     * FastCGI's server Port.
     *
     * @var int
     */
    protected $port;

    /**
     * Timeout.
     *
     * @var ?int
     */
    protected $timeout;

    /**
     * Custom Response Factory.
     *
     * @var ResponseFactoryInterface
     */
    protected $responseFactory;

    /**
     * The opened socket.
     *
     * @var Resource
     */
    protected $socket;

    /**
     * Whether to keep the connection alive after the request
     *
     * @var bool
     */
    protected $keepAlive = false;

    /**
     * Request counter
     *
     * @var int
     */
    protected $requestCounter = 0;

    /**
     * Requests
     *
     * @var int[]
     */
    protected $pendingRequests = [];

    /**
     * Completed responses stored for access after request is removed from _requests
     *
     * @var array<int, ResponseInterface>
     */
    protected $completedResponses = [];

    /**
     * @param string $host Host of the FastCGI application or path to the FastCGI unix socket
     * @param ?int $port Port of the FastCGI application or null for the FastCGI unix socket
     */
    public function __construct(
//        string $scriptFilename,
        string $host,
        ?int $port = null,
        ?int $timeout = null,
        ?Functions $functions = null,
        ?ResponseFactoryInterface $responseFactory = null,
    ) {
        $this->host = $host;
        $this->port = $port ?? 0;
        $this->timeout = $timeout;
        $this->functions = $functions ?? new Functions();
        $this->responseFactory = $responseFactory ?: Factory::getResponseFactory();
    }

    /**
     * @return PromiseInterface<ResponseInterface>
     */
    public function sendAsync(RequestInterface $request): PromiseInterface
    {
        $this->connect();

        $fastCgiRequest = FastCgiRequest::fromPsrRequest($request);
        $params = $fastCgiRequest->params();
        $params['SCRIPT_FILENAME'] = '/var/www/html/public/index.php';

        $stdin = $fastCgiRequest->body();

        $id = $this->nextRequestId();

        // -----------------------------------------------------------------------------
        // A FastCGI request is not a single blob of data.
        // It's a sequence of packets, sent in order, each with a specific type.
        // -----------------------------------------------------------------------------
        // In our case the order is:
        //   - BEGIN_REQUEST
        //   - (ENVIRONMENT) PARAMS
        //   - STDIN
        // -----------------------------------------------------------------------------

        $fastCgiRequest = $this->buildRecord(
            self::TYPE_BEGIN_REQUEST,
            chr(0) . chr(self::RESPONDER) . chr((int) $this->keepAlive) . str_repeat(chr(0), 5),
            $id
        );

        $fastCgiRequestParams = '';
        foreach ($params as $key => $value) {
            $fastCgiRequestParams .= $this->buildNameValuePair($key, $value);
        }

        // -----------------------------------------------------------------------------
        // Start of PARAMS record (add if exists)
        // -----------------------------------------------------------------------------

        // Add extracted values from RequestInterface (REQUEST_METHOD, REQUEST_URI, HTTP_HOST) as (ENV) PARAMS
        if ($fastCgiRequestParams) {
            $fastCgiRequest .= $this->buildRecord(self::TYPE_PARAMS, $fastCgiRequestParams, $id);
        }

        // -----------------------------------------------------------------------------
        // Signal the end of PARAMS (doesn't matter if its empty)
        // -----------------------------------------------------------------------------
        // The application will keep reading from PARAMS until it encounters an
        // end-of-stream indication, which is a PARAMS record with contentLength = 0
        // -----------------------------------------------------------------------------
        $fastCgiRequest .= $this->buildRecord(self::TYPE_PARAMS, '', $id);

        // -----------------------------------------------------------------------------
        // Start of STDIN record (add if exists)
        // -----------------------------------------------------------------------------

        // Add the body (JSON, form data, etc.) of RequestInterface to STDIN
        if ($stdin) {
            $fastCgiRequest .= $this->buildRecord(self::TYPE_STDIN, $stdin, $id);
        }

        // -----------------------------------------------------------------------------
        // Signal the end of STDIN (doesn't matter if its empty)
        // -----------------------------------------------------------------------------
        // The application will keep reading from STDIN until it encounters an
        // end-of-stream indication, which is a STDIN record with contentLength = 0
        // -----------------------------------------------------------------------------
        $fastCgiRequest .= $this->buildRecord(self::TYPE_STDIN, '', $id);

        if ($this->functions->socket_write($this->socket, $fastCgiRequest) === false) {
            // Socket write failed — consider reconnecting or handling at a higher level
            throw SocketException::socketWrite($this->socket);
        }

        // Create a promise that will be resolved when the response is available
        $promise = new Promise(
            // Resolver function
            function () use (&$promise, $id) {
                // wait for response and resolve the promise
                try {
                    $response = $this->waitForResponseAndParse($id);
                    $promise->resolve($response);
                } catch (\Exception $e) {
                    $promise->reject($e);
                }
            }
        );

        // Track the request using the response object
        $this->pendingRequests[] = $id;

        return $promise;
    }

    /**
     * Wait for response and parse it into a PSR response
     */
    public function waitForResponseAndParse(int $requestId): ResponseInterface
    {
        $this->waitForResponse($requestId, $this->timeout ?? 30000);

        if (!isset($this->completedResponses[$requestId])) {
            throw new InvalidArgumentException("Request ID not found after waiting for response");
        }

        $response = $this->completedResponses[$requestId];

        // Clean up the completed response now that we've used it
        unset($this->completedResponses[$requestId]);

        return $response;
    }

    private static function contains(string $needle, string $haystack): bool
    {
        return strpos($haystack, $needle) !== false;
    }

    /**
     * Parses a raw FastCGI response.
     *
     * @return array{headers: string[], body: string, status: int}
     *     - headers: Content-Type: Context-Type: ['text/html' ...]
     *     - body: HTML content, JSON ...
     *     - status: 200, 404 ...
     */
    protected function parseFastCgiResponse(string $response): array
    {
        /**
         * Example $response string with 200:
         *
         *    Content-Type: application/json\r\n
         *    Auth: Bearer XXXX-XXX-XXX\r\n
         *    \r\n
         *    <html>Example</html>
         *
         * Example $response string with != 200 (Status header is included):
         *
         *    Status: 400 Bad Request\r\n
         *    Content-Type: application/json\r\n
         *    Auth: Bearer XXXX-XXX-XXX\r\n
         *    \r\n
         *    <html>Example</html>
         */

        $parts = explode("\r\n\r\n", $response, 2);

        /** @var array<string,string|string[]> $headers */
        $headers = [];
        if (isset($parts[0])) {
            $headerLines = explode("\r\n", $parts[0]);

            foreach ($headerLines as $line) {
                // exclude lines that don't look like a "header: value" pair (see the example above)
                if (!self::contains(':', $line)) {
                    continue;
                }

                list($name, $value) = explode(':', $line, 2);

                $name = trim($name);
                $value = trim($value);

                // headers can have multiple values, so we consider them arrays
                if (!isset($headers[$name])) {
                    $headers[$name] = [];
                }

                $headers[$name][] = $value;
            }
        }

        $body = $parts[1] ?? '';

        // FastCGI adds the HTTP status to where we extract the headers from (see the example above for 400)
        $statusCode = 200;
        if (isset($headers['Status'])) {
            // extract the numeric part (e.g., 400 from "400 Bad Request")
            $statusParts = explode(' ', $headers['Status'], 2);
            $statusCode = (int) $statusParts[0];

            // remove the 'Status header' so it's not treated as an HTTP header
            unset($headers['Status']);
        }

        return [
            'headers' => $headers,
            'body' => $body,
            'status' => $statusCode
        ];
    }

    /**
     * Create a PSR-7 ResponseInterface from headers and body
     * @throws SocketException
     */
    protected function createPsrResponse(string $stdout, string $stderr): ResponseInterface
    {
        $fastCgiResponse = $this->parseFastCgiResponse($stdout);

        // if there was stderr output, consider it an error
        if (!empty($stderr)) {
            throw SocketException::forStderrResponse($stderr);
        }

//        // create a stream for the body
//        $bodyStream = new \Laminas\Diactoros\Stream('php://temp', 'r+');
//        $bodyStream->write($fastCgiResponse['body']);
//        $bodyStream->rewind();
//
//        // create the PSR-7 response
//        $response = new \Laminas\Diactoros\Response(
//            $bodyStream,
//            $fastCgiResponse['status'],
//        );

        $response = $this->responseFactory->createResponse($fastCgiResponse['status']);
        $response->getBody()->write($fastCgiResponse['body']);

        foreach ($fastCgiResponse['headers'] as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Parses a FastCGI Record header.
     *
     * @return array{
     *     version: int,
     *     type: int,
     *     requestId: int,
     *     contentLength: int,
     *     paddingLength: int,
     *     reserved: int
     * }
     */
    protected function parseRawRecordHeader(string $recordHeader): array
    {
        // header size is 8 bytes
        $result = [];

        // -----------------------------------------------------------------------------
        // Protocol Version, always set to 1
        // -----------------------------------------------------------------------------
        // byte offset: 0
        // reserved size: 1 byte
        // -----------------------------------------------------------------------------
        $result['version'] = ord($recordHeader[0]);

        // -----------------------------------------------------------------------------
        // The type of the FastCGI record Type, e.g.: BEGIN_REQUEST, STDOUT, END_REQUEST, etc.
        // -----------------------------------------------------------------------------
        // byte offset: 1
        // reserved size: 1 byte
        // -----------------------------------------------------------------------------
        $result['type'] = ord($recordHeader[1]);

        // -----------------------------------------------------------------------------
        // An identifier for the request.
        // This allows multiple requests to be multiplexed over a single connection
        // -----------------------------------------------------------------------------
        // byte offset: 2-3
        // reserved size: 2 bytes
        // -----------------------------------------------------------------------------
        $result['requestId'] = (ord($recordHeader[2]) << 8) + ord($recordHeader[3]);

        // -----------------------------------------------------------------------------
        // The length of the content data in the record
        // -----------------------------------------------------------------------------
        // byte offset: 4-5
        // reserved size: 2 bytes
        // -----------------------------------------------------------------------------
        $result['contentLength'] = (ord($recordHeader[4]) << 8) + ord($recordHeader[5]);

        // -----------------------------------------------------------------------------
        // The length of the padding that follows the content data
        // -----------------------------------------------------------------------------
        // byte offset: 6
        // reserved size: 1 byte
        // -----------------------------------------------------------------------------
        $result['paddingLength'] = ord($recordHeader[6]);

        // -----------------------------------------------------------------------------
        // Reserved for future use; should be set to 0
        // -----------------------------------------------------------------------------
        // byte offset: 7
        // reserved size: 1 byte
        // -----------------------------------------------------------------------------
        $result['reserved'] = ord($recordHeader[7]);

        return $result;
    }

    /**
     * Read a FastCGI record.
     *
     * @return null|array{
     *     version: int,
     *     type: int,
     *     requestId: int,
     *     contentLength: int,
     *     paddingLength: int,
     *     reserved: int,
     *     content: string
     * }
     *
     * @throws SocketException
     * @throws TimeoutException
     */
    protected function readRecord(int $timeoutMs): ?array
    {
        // get set timeout in seconds (if higher than 0 set to at least 1 second?)
        $seconds = (int)(floor($timeoutMs / 1000));
        $microseconds = ($timeoutMs % 1000) * 1000;

        // Wait for the socket to become ready for reading (blocking operation)
        $select = $this->functions->socket_select(
            [$this->socket],
            null,
            null,
            $seconds,
            $microseconds
        );

        if ($select === 0) {
            throw SocketException::forTimeout($seconds);
        } elseif ($select === false) {
            $errorCode = $this->functions->socket_last_error($this->socket);
            $errorMessage = $this->functions->socket_strerror($errorCode);

            // TODO pretty clear
//            throw SocketException::forSelect($errorCode, $errorMessage);
        }

        // A FastCGI record always starts with an 8-byte header
        $rawHeader = $this->functions->socket_read($this->socket, self::HEADER_LENGTH);

        // no more data to read (graceful end of stream)
        if ($rawHeader === '') {
            return null;
        }

        // indicates an error (e.g.: the socket was closed abruptly, a disconnection occurred, etc.)
        if ($rawHeader === false) {
            $errorCode = $this->functions->socket_last_error();
            $errorMessage = $this->functions->socket_strerror($errorCode);

            throw SocketException::forRead($errorCode, $errorMessage);
        }

        $header = $this->parseRawRecordHeader($rawHeader);

        // -------------------------------------------------------------------------------------------
        // Example $header['content'] value:
        // -------------------------------------------------------------------------------------------
        // Content-Type: text/html\r\n
        // \r\n
        // <html>Example</html>
        // -------------------------------------------------------------------------------------------
        $header['content'] = '';

        if ($header['contentLength']) {
            $length  = $header['contentLength'];

            // read the content in chunks until we reach the full contentLength
            while ($length && $buffer = $this->functions->socket_read($this->socket, $length)) {
                $length -= strlen($buffer);
                $header['content'] .= $buffer;
            }
        }

        // -------------------------------------------------------------------------------------------
        // Discard padding bytes to maintain stream alignment.
        // -------------------------------------------------------------------------------------------
        // The FastCGI protocol uses padding to ensure each record aligns to an 8-byte boundary.
        // Although padding has no semantic value, failing to consume it would desynchronize
        // the stream and corrupt later reads.
        // -------------------------------------------------------------------------------------------
        if ($header['paddingLength']) {
            $this->functions->socket_read($this->socket, $header['paddingLength']);
        }

        return $header;
    }

    /**
     * Blocking call that waits for response to specific request
     *
     * @param int $requestId
     * @param int $timeoutMs the number of milliseconds to wait for the response
     *
     * @throws CommunicationException
     * @throws TimeoutException
     */
    public function waitForResponse(int $requestId, int $timeoutMs): bool
    {
        if (!in_array($requestId, $this->pendingRequests)) {
            throw new CommunicationException('Invalid request id given');
        }

        // Need to manually check since we might do several reads none of which timeout themselves
        // but still not get the response requested
        $startTime = $this->functions->microtime();
        $stdout = '';
        $stderr = '';

        do {
            $fcgiResponse = $this->readRecord($timeoutMs);

            // No data available at the moment — retry until a valid record is received or timeout occurs.
            // This may happen if the socket is still open, but no record has been sent yet.
            if (!$fcgiResponse) {
                continue;
            }

            $obtainedRequestId = $fcgiResponse['requestId'];

            // the obtained request ID is not tracked. Belongs to another client?
            if (!in_array($obtainedRequestId, $this->pendingRequests)) {
                trigger_error("Bad requestID: " . $fcgiResponse['requestId'], E_USER_WARNING);
            }

            $fcgiResponseType = (int)$fcgiResponse['type'];

            if ($fcgiResponseType === self::TYPE_STDOUT) {
                $stdout .= $fcgiResponse['content'];
            } elseif ($fcgiResponseType === self::TYPE_STDERR) {
                $stderr .= $fcgiResponse['content'];
            } elseif ($fcgiResponseType === self::TYPE_END_REQUEST) {
                // store completed response
                $this->completedResponses[$obtainedRequestId] = $this->createPsrResponse(
                    $stdout,
                    $stderr
                );

                // the request is now complete, it can be safely removed from pending requests
                unset($this->pendingRequests[$obtainedRequestId]);

                // the request ended, should we keep the connection alive?
                $this->handleKeepAlive();

                // this check is essential to ensure we're resolving the correct request
                // on a concurrency/multiplexing request handling context
                if ($obtainedRequestId === $requestId) {
                    return true;
                }
            }

            // -----------------------------------------------------------------------------
            // Interpret FastCGI protocolStatus (byte 4 of END_REQUEST content)
            // This byte gives additional information about why the request ended.
            // -----------------------------------------------------------------------------
            // According to the spec, possible values are:
            //
            //   - 0: REQUEST_COMPLETE -> normal completion
            //   - 1: CANT_MPX_CONN    -> multiplexing not supported by application
            //   - 2: OVERLOADED       -> server overloaded, cannot handle request
            //   - 3: UNKNOWN_ROLE     -> the role specified was not understood
            // -----------------------------------------------------------------------------
            if ($fcgiResponse['type'] === self::TYPE_END_REQUEST && isset($fcgiResponse['content'][4])) {
                $msg = ord($fcgiResponse['content'][4]);

                // we only check the errors here (> 0)
                if ($msg === self::STATUS_CANT_MPX_CONN) {
                    throw new CommunicationException('This app can\'t multiplex [CANT_MPX_CONN]');
                } elseif ($msg === self::STATUS_OVERLOADED) {
                    throw new CommunicationException('New request rejected; too busy [OVERLOADED]');
                } elseif ($msg === self::STATUS_UNKNOWN_ROLE) {
                    throw new CommunicationException('Role value not known [UNKNOWN_ROLE]');
                }
            }

            $endTime = $this->functions->microtime();
            if ($timeoutMs && ($endTime - $startTime) >= ($timeoutMs / 1000)) {
                throw new TimeoutException('Timed out');
            }

        } while (true);
    }

    /**
     * Helper to avoid duplicating milliseconds to secs/usecs in a few places
     */
    protected function setTimeout(): bool
    {
        $timeout = [
            'sec' => floor($this->timeout / 1000),
            'usec' => ($this->timeout % 1000) * 1000,
        ];

        return $this->functions->socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, $timeout);
    }

    /**
     * Makes sure to generate an ID that fits within 16-bit unsigned range (0–65535),
     * as required by FastCGI for request IDs (big-endian, 2 bytes).
     *
     * It also makes sure to not use an already taken ID.
     */
    protected function nextRequestId(): int
    {
        do {
            $this->requestCounter++;
            if ($this->requestCounter >= 65536) {
                $this->requestCounter = 1;
            }
        } while (in_array($this->requestCounter, $this->pendingRequests));

        return $this->requestCounter;
    }

    /**
     * Builds a FastCGI record.
     *
     * @param int $type Type of the record
     * @param string $content Content of the record
     * @param int $requestId RequestId
     */
    protected function buildRecord(int $type, string $content, int $requestId): string
    {
        $offset = 0;
        $contentLength = strlen($content);
        $buffer = '';

        // each record can have a maximum of 65535 bytes
        // so we split it into chunks of 65535 bytes each
        do {
            $chunk = substr($content, $offset, 0xffff - 8);
            $chunkLength = strlen($chunk);

            // build 8 byte header and append the content
            $buffer .= chr(self::VERSION_1)              // version (1 byte, always 1)
                     . chr($type)                        // type (1 byte: BEGIN_REQUEST, STDOUT, etc.)
                     . chr(($requestId >> 8) & 0xFF)     // requestIdB1 (high byte)
                     . chr($requestId & 0xFF)            // requestIdB0 (low byte)
                     . chr(($chunkLength >> 8) & 0xFF)   // contentLengthB1 (high byte)
                     . chr($chunkLength & 0xFF)          // contentLengthB0 (low byte)
                     . chr(0)                            // paddingLength (always 0 here)
                     . chr(0)                            // reserved (always 0)
                     . $chunk;                           // content payload (variable length)

            $offset += $chunkLength;
        } while ($offset < $contentLength);

        return $buffer;
    }

    /**
     * Encodes a FastCGI Name-Value pair.
     *
     * Each name and value is length-prefixed, using 1 or 4 bytes depending on their size.
     * - If the length is < 128, it is encoded in a single byte.
     * - If the length is >= 128, it is encoded in 4 bytes with the most significant bit of the first byte set to 1.
     *
     * FastCGI Spec format:
     *   [nameLength] [valueLength] [nameBytes] [valueBytes]
     *
     * @param string $name  The name part of the pair (e.g., "SCRIPT_FILENAME")
     * @param string $value The value part of the pair (e.g., "/var/www/index.php")
     * @return string The binary-encoded Name-Value pair.
     */
    protected function buildNameValuePair(string $name, string $value): string
    {
        $nameLength = strlen($name);
        $valueLength = strlen($value);
        $nameValuePair = '';

        // Encode name length (FastCGI spec: 1 or 4 bytes depending on size)
        if ($nameLength < 128) {
            // 1-byte length (MSB 0)
            $nameValuePair .= chr($nameLength);               // nameLengthB0 (1 byte, fits in single byte)
        } else {
            // 4-byte length with MSB of first byte set to 1
            $nameValuePair .= chr(($nameLength >> 24) | 0x80) // nameLengthB3 (1 byte, high byte with MSB = 1)
                     . chr(($nameLength >> 16) & 0xFF)        // nameLengthB2 (1 byte)
                     . chr(($nameLength >> 8) & 0xFF)         // nameLengthB1 (1 byte)
                     . chr($nameLength & 0xFF);               // nameLengthB0 (1 byte, low byte)
        }

        // Encode value length (same encoding strategy as name)
        if ($valueLength < 128) {
            $nameValuePair .= chr($valueLength);               // valueLengthB0 (1 byte, fits in single byte)
        } else {
            // 4-byte length with MSB of first byte set to 1
            $nameValuePair .= chr(($valueLength >> 24) | 0x80) // valueLengthB3 (1 byte, high byte with MSB = 1)
                     . chr(($valueLength >> 16) & 0xFF)        // valueLengthB2 (1 byte)
                     . chr(($valueLength >> 8) & 0xFF)         // valueLengthB1 (1 byte)
                     . chr($valueLength & 0xFF);               // valueLengthB0 (1 byte, low byte)
        }

        // Append actual bytes
        return $nameValuePair . $name . $value;
    }

    /**
     * Establishes a socket connection to the FastCGI application.
     *
     * Connects to either a TCP or Unix domain socket, depending on the configuration.
     *
     * @throws SocketException On socket creation failure or connection Timeout.
     */
    protected function connect(): void
    {
        if ($this->socket) {
            return;
        }

        $address = $this->host;
        if ($this->port) {
            $this->socket =  $this->functions->socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            $port = $this->port;
        } else {
            $this->socket = $this->functions->socket_create(AF_UNIX, SOCK_STREAM, 0);
            $port = 0;
        }

        if ($this->socket === false) {
            $errorCode = $this->functions->socket_last_error();
            $errorMessage = $this->functions->socket_strerror($errorCode);

            throw SocketException::forCreate($errorCode, $errorMessage);
        }

        $connect = $this->functions->socket_connect($this->socket, $address, $port);
        if ($connect === false) {
            $errorCode = $this->functions->socket_last_error();
            $errorMessage = $this->functions->socket_strerror($errorCode);

            throw SocketException::forConnect($errorCode, $errorMessage, $this->host, $this->port);
        }

        if ($this->timeout && !$this->setTimeout()) {;
            throw SocketException::forTimeout($this->timeout);
        }
    }


    protected function handleKeepAlive(): void
    {
        if (!$this->keepAlive && $this->socket !== null) {
            $this->functions->socket_clear_error($this->socket);
            $this->functions->socket_close($this->socket);
            $this->socket = null;
        }
    }
}
