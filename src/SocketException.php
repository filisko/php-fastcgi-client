<?php
declare(strict_types=1);

namespace Filisko;

use RuntimeException;

class SocketException extends RuntimeException
{
    public static function forCreate(int $code, string $message): self
    {
        return new self("Socket could not be created: $message ($code)", $code);
    }

    public static function forConnect(int $code, string $message, string $host, ?int $port = null): self
    {
        if ($port) {
            $host .= ":$port";
        }

        return new self("Failed to connect to $host: $message ($code)", $code);
    }

    public static function forRead(int $code, string $message): self
    {
        return new self("Socket could not read more data: $message ($code)", $code);
    }

    public static function socketWrite($socket)
    {
        if ($socket === null) {
            return self::requestAborted();
        }

        return self::socketError('Failure writing socket', $socket);
    }

    public static function socketError($message, $socket)
    {
        $err = socket_last_error($socket);
        return new self("$message - $err - ".socket_strerror($err));
    }

    public static function requestAborted()
    {
        return new self("The request was aborted.");
    }

    public static function forTimeout(int $timeout)
    {
        return new self("Failed to set timeout to $timeout ms");
    }

    public static function forStderrResponse(string $stderr)
    {
        return new self("FastCGI returned a response with stderr: $stderr");
    }
}
