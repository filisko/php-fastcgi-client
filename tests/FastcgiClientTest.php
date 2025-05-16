<?php
declare(strict_types=1);

namespace Filisko\Tests;

use Filisko\FakeFallback;
use Filisko\FakeFunctions;
use Filisko\FakeStack;
use Filisko\FakeStatic;
use Filisko\FastcgiClient;
use Filisko\SocketException;
use Filisko\Tests\Helpers\FastcgiTestHelper;
use PHPUnit\Framework\TestCase;
use Sunrise\Http\Message\Request;

class FastcgiClientTest extends TestCase
{
    public function test_it_throws_an_exception_when_socket_could_not_created(): void
    {
        $functions = new FakeFunctions([
            'socket_create' => false,
            'socket_last_error' => 93,
            'socket_strerror' => 'Protocol not supported',
        ]);

        $client = new FastcgiClient('10.5.0.2', 9000, [
            'SCRIPT_FILENAME' => '/var/www/html/public/index.php',
        ], 500, null, $functions);

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

        $client = new FastcgiClient('10.5.0.2', 9000, [
            'SCRIPT_FILENAME' => '/var/www/html/public/index.php',
        ], 500, null, $functions);

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Failed to connect to 10.5.0.2:9000: No route to host (113)');
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

        $client = new FastcgiClient('10.5.0.2', null, [
            'SCRIPT_FILENAME' => '/var/www/html/public/index.php',
        ], 500, null, $functions);

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Failed to connect to 10.5.0.2: No route to host (113)');
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

        $client = new FastcgiClient('10.5.30.2', 9000, [
            'SCRIPT_FILENAME' => '/var/www/html/public/index.php',
        ], 3000, null, $functions);

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Failed to set timeout to 3000 ms');

        $request = new Request();
        $client->sendAsync($request);
    }

    public function test_it_throws_exception_when_scripFilename_is_not_passed_in_the_constructor(): void
    {
        $client = new FastcgiClient('10.5.0.2', 9000, [], 1000, null, new FakeFunctions([], true));

        $request = new Request('GET', 'https://filis.app.local/identity/register');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SCRIPT_FILENAME is required for FastCGI requests');

        $client->sendAsync($request);
    }

    protected static function successfulResponse(): FakeFunctions
    {
        $socketData = FastcgiTestHelper::responseWithStdoutOnly("Content-Type: text/html\r\n\r\nHello");

        $position = 0;

        return new FakeFunctions([
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
    }

    public function test_success_with_params_on_request(): void
    {
        $client = new FastcgiClient('10.5.0.2', 9000, [], 1000, null, self::successfulResponse());

        $request = new Request('GET', 'https://filis.app.local/identity/register');

        $response = $client->sendAsync($request, [
            'SCRIPT_FILENAME' => '/var/www/html/public/index.php',
        ]);

        $this->assertEquals("Hello", (string)$response->wait()->getBody());
    }

    public function test_success_with_params_in_constructor(): void
    {
        $client = new FastcgiClient('10.5.0.2', 9000, [
            'SCRIPT_FILENAME' => '/var/www/html/public/index.php',
        ], 500, null, self::successfulResponse());

        $request = new Request('GET', 'https://filis.app.local/identity/register');

        $response = $client->sendAsync($request);

        $this->assertEquals("Hello", (string)$response->wait()->getBody());
    }
}
