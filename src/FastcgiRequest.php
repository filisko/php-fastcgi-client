<?php
declare(strict_types=1);

namespace Filisko;

use Psr\Http\Message\RequestInterface;

/**
 * @internal Used to generate a FastCGI request from a PSR-7 request
 */
class FastcgiRequest
{
    /**
     * Environment variables, but in FastCGI's lang it's Params
     *
     * @var array<string,string>
     */
    private $params;

    /**
     * @var string|null
     */
    private $body;

    public function __construct(array $params, ?string $body = null)
    {
        $this->params = $params;
        $this->body = $body;
    }

    public function params(): array
    {
        return $this->params;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    public static function fromPsrRequest(RequestInterface $request): self
    {
        $uri = $request->getUri();

        /** @var array<string,string> $env */
        $env = [
            'REQUEST_METHOD' => $request->getMethod(),
            'REQUEST_URI' => $uri->getPath() === '' ? '/' : $uri->getPath(),
        ];

        $fastCgiSupportedHeaders = ['Content-Type', 'Content-Length'];

        $headers = $request->getHeaders();

        foreach ($headers as $header => $values) {
            $fastCgiHeader = str_replace('-', '_', strtoupper($header));

            $fastCgiHeaderValue = $request->getHeaderLine($header);

            if (in_array($header, $fastCgiSupportedHeaders)) {
                $env[$fastCgiHeader] = $fastCgiHeaderValue;
            } else {
                $env['HTTP_' . $fastCgiHeader] = $fastCgiHeaderValue;
            }
        }

        $body = (string)$request->getBody();
        if ($body) {
            $env['CONTENT_LENGTH'] = (string)strlen($body);
        }

        $query = $uri->getQuery();
        if ($query) {
            $env['QUERY_STRING'] = $query;
        }

//        GATEWAY_INTERFACE

        return new self($env, $body ?: null);
    }
}
