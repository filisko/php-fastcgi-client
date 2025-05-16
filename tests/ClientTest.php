<?php
declare(strict_types=1);

namespace Filisko\Tests;

use Filisko\FakeFallback;
use Filisko\FakeFunctions;
use Filisko\FakeStack;
use Filisko\FakeStatic;
use Filisko\FastCgiClient;
use Filisko\SocketException;
use PHPUnit\Framework\TestCase;
use Sunrise\Http\Message\Request;

class ClientTest extends TestCase
{
//    public function test_it_throws_an_exception_when_there_arent_modules(): void
//    {
//        $functions = new FakeFunctions([]);
////        $functions = new FakeFunctions([], true);
//
//        $client = new ClientOld('10.5.0.2', 9000,  $functions);
//
//        $postData = http_build_query([
//            'username' => 'john_doe',
//            'email' => 'john@example.com',
//            'message' => 'Hello, this is a test POST request!'
//        ]);
//
//
//        $content = 'key=value';
//        $s = $client->request(
//            [
//                "HTTP_HOST" => "filis.trekkly.local",
//                'HTTP_X_AUTH' => 'ASD',
//                'REQUEST_METHOD'  => 'POST',
//                "SCRIPT_FILENAME" => "/var/www/html/public/index.php",
//                "REQUEST_URI" => "/identity/register",
//                'CONTENT_TYPE'    => 'application/x-www-form-urlencoded',
//                'CONTENT_LENGTH'  => (string)strlen($content),
////                "QUERY_STRING" => "",
//            ],
//            $content
//        );
//
//        dump($s);
//    }

    public function test_it_throws_an_exception_when_socket_could_not_created(): void
    {
        $functions = new FakeFunctions([
            'socket_create' => false,
            'socket_last_error' => 93,
            'socket_strerror' => 'Protocol not supported',
        ]);

        $client = new FastCgiClient('10.5.32.2', 9000, null, $functions);

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage("Socket could not be created: Protocol not supported (93)");
        $this->expectExceptionCode(93);

        $request = new Request();
        $client->sendAsync($request);
    }

    public function test_it_throws_an_exception_when_socket_could_not_connect_to_host(): void
    {
        $functions = new FakeFunctions([
            'socket_connect' => false,
            'socket_last_error' => 113,
            'socket_strerror' => 'No route to host',
        ]);

        $client = new FastCgiClient('10.5.32.2', 9000, null, $functions);

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Failed to connect to 10.5.32.2:9000: No route to host (113)');
        $this->expectExceptionCode(113);

        $request = new Request();
        $client->sendAsync($request);
    }

    public function test_it_throws_an_exception_when_socket_could_not_connect_to_host_without_port(): void
    {
        $functions = new FakeFunctions([
            'socket_connect' => false,
            'socket_last_error' => 113,
            'socket_strerror' => 'No route to host',
        ]);

        $client = new FastCgiClient('10.5.32.2', null, null, $functions);

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Failed to connect to 10.5.32.2: No route to host (113)');
        $this->expectExceptionCode(113);

        $request = new Request();
        $client->sendAsync($request);
    }

    public function test_it_throws_an_exception_when_socket_timeout_could_not_be_set(): void
    {
        $functions = new FakeFunctions([
            'socket_connect' => true,
            'socket_set_option' => false
        ]);

        $client = new FastCgiClient('10.5.30.2', 9000, 3000, $functions);

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Failed to set timeout to 3000 ms');

        $request = new Request();
        $client->sendAsync($request);
    }

    public function test_it_sends_requests(): void
    {
        // a FastCGI response consists of STDOUT, STDERR (optional) and END_REQUEST
        // in this test we only include STDOUT and END_REQUEST
       // combined socket data (fetched piece by piece)
        $socketData = FastCgiTestHelper::responseWithStdoutOnly("Content-Type: text/html\r\n\r\nHello");

        $position = 0;

        $functions = new FakeFunctions([
            'socket_create' => new FakeFallback,
            'socket_connect' => true,
            'socket_set_option' => true,
            'socket_write' => true,
            'socket_select' => new FakeStatic(1),
            'socket_read' => new FakeStatic(function($socket, $length) use (&$socketData, &$position) {
                // stop reading socket's data if everything was read already according to content's length
                if ($position >= strlen($socketData)) {
                    return null;
                }

                // simulate fetching data in different bits & bytes according to $length's value
                $data = substr($socketData, $position, $length);
                $position += strlen($data);

                return $data;
            }),
            'socket_last_error' => 0,
            // these two are used to close the connection (keepAlive = false)
            'socket_clear_error' => true,
            'socket_close' => true,
            // socket_read takes less than 1 sec. (no timeout)
            'microtime' => new FakeStack([1747210962, 1747210962]),
        ], true);

//        $client = new FastCgiClient('10.5.0.2', 9000, 500);
        $client = new FastCgiClient('10.5.0.2', 9000, 1000, $functions);

        $request = new Request('GET', 'https://filis.trekkly.local/identity/register');
        $response = $client->sendAsync($request);

//        dump($client->waitForResponseAndParse(1)->getStatusCode());
//        dump($client->waitForResponseAndParse(1)->getStatusCode());
//        dd($response->wait());

//        dump($response->wait()->getHeaders());
        $this->assertEquals("Hello", (string)$response->wait()->getBody());

//        $this->assertEquals("Hello", $client->waitForResponse(1));
    }

    public function test_it_handles_stderr_response(): void
    {
        // a FastCGI response consists of STDOUT, STDERR (optional) and END_REQUEST
        // in this test we include all of them, especially STDERR, to simulate an error

        $socketData = FastCgiTestHelper::responseWithStderr(
            "Content-Type: text/html\r\n\r\nHello",
            "Something went wrong"
        );

        $position = 0;

        $functions = new FakeFunctions([
            'socket_create' => new FakeFallback,
            'socket_connect' => true,
            'socket_set_option' => true,
            'socket_write' => true,
            'socket_select' => new FakeStatic(5),
            'socket_read' => new FakeStatic(function ($socket, $length) use (&$socketData, &$position) {
                if ($position >= strlen($socketData)) {
                    return null;
                }

                $data = substr($socketData, $position, $length);
                $position += strlen($data);
                return $data;
            }),
            'socket_last_error' => 0,
            'microtime' => new FakeStack([1747210962, 1747210962, 1747210962]),
        ], true);

        $client = new FastCgiClient('10.5.0.2', 9000, 500, $functions);
        $request = new Request('GET', 'https://filis.trekkly.local/identity/register');

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('FastCGI returned a response with stderr: Something went wrong');

        $response = $client->sendAsync($request);
        $response->wait();
    }
}
