<?php
declare(strict_types=1);

namespace Filisko;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface FastcgiClientInterface
{
    /**
     * Send a sync request to the FastCGI server.
     *
     * @param array<string,string> $params FastCGI environment variables. SCRIPT_FILENAME is required.
     *
     * @return PromiseInterface<ResponseInterface>
     */
    public function sendAsync(RequestInterface $request, array $params = []): PromiseInterface;
}
