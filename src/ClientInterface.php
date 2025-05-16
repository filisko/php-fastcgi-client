<?php
declare(strict_types=1);

namespace Filisko;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface ClientInterface
{
    /**
     * Send a sync request to the FastCGI server.
     * @return PromiseInterface<ResponseInterface>
     */
    public function sendAsync(RequestInterface $request): PromiseInterface;

//    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface;
}
