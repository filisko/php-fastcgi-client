<?php
declare(strict_types=1);

namespace Filisko\Tests;

use Filisko\FastcgiRequest;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\TestCase;

class FastcgiRequestTest extends TestCase
{
    public function test_defaults(): void
    {
        $request = new Request('GET', 'https://app.local/');

        $fastCgiRequest = FastcgiRequest::fromPsrRequest($request);

        $expectedFastCgiRequest = new FastcgiRequest([
            'REQUEST_URI' => '/',
            'REQUEST_METHOD'  => 'GET',
            'HTTP_HOST' => 'app.local'
        ]);

        $this->assertEquals(
            $expectedFastCgiRequest,
            $fastCgiRequest
        );
    }

    public function test_psr7_to_fastcgi_mapping(): void
    {
        $json = json_encode([
            'key' => 'value'
        ]);

        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $json);
        rewind($resource);

        $body = new Stream($resource);

        $psrRequest = new Request('PUT', 'https://subdomain.example.com/auth/login?key=value', [
            'Content-Type' => 'application/json',
            'X-Auth' => 'xxxxx',
        ], $body);

        $fastCgiRequest = FastcgiRequest::fromPsrRequest($psrRequest);

        $expectedFastCgiRequest = new FastcgiRequest([
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
