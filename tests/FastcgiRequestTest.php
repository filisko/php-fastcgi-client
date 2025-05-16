<?php
declare(strict_types=1);

namespace Filisko\Tests;

use Filisko\FastcgiRequest;
use PHPUnit\Framework\TestCase;
use Sunrise\Http\Message\Request;
use Sunrise\Http\Message\Stream;

class FastcgiRequestTest extends TestCase
{
    public function test_defaults(): void
    {
        $psrRequest = new Request();

        $fastCgiRequest = FastcgiRequest::fromPsrRequest($psrRequest);

        $expectedFastCgiRequest = new FastcgiRequest([
            'REQUEST_URI' => '/',
            'REQUEST_METHOD'  => 'GET',
        ]);

        $this->assertEquals(
            $expectedFastCgiRequest,
            $fastCgiRequest
        );
    }

    public function test_it_throws_an_exception_when_there_arent_modules(): void
    {
        $json = json_encode([
            'key' => 'value'
        ]);

        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $json);
        rewind($resource);

        $body = new Stream($resource);

        $psrRequest = new Request('PUT', '/auth/login?key=value', [
            'Content-Type' => 'application/json',
            'Host' => 'subdomain.example.com',
            'X-Auth' => 'xxxxx',
        ], $body);

        $fastCgiRequest = FastcgiRequest::fromPsrRequest($psrRequest);

        $expectedFastCgiRequest = new FastcgiRequest([
//            "SCRIPT_FILENAME" => "/var/www/html/public/index.php",
            'REQUEST_METHOD'  => 'PUT',
            'REQUEST_URI' => '/auth/login',
            'HTTP_X_AUTH' => 'xxxxx',
            'HTTP_HOST' => 'subdomain.example.com',
            'CONTENT_TYPE'    => 'application/json',
            'CONTENT_LENGTH'  => (string)strlen($json),
            'QUERY_STRING' => 'key=value',
        ], $json);

        $this->assertEquals(
            $expectedFastCgiRequest,
            $fastCgiRequest
        );
    }
}
