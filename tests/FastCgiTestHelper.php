<?php
declare(strict_types=1);

namespace Filisko\Tests;

class FastCgiTestHelper
{
    public static function buildRecord(int $type, int $requestId, string $content = ''): string
    {
        $length = strlen($content);

        $header =
            "\x01" .                             // version
            chr($type) .                         // type
            chr(($requestId >> 8) & 0xFF) .      // requestId high byte
            chr($requestId & 0xFF) .             // requestId low byte
            chr(($length >> 8) & 0xFF) .         // content length high byte
            chr($length & 0xFF) .                // content length low byte
            "\x00" .                             // padding length
            "\x00";                              // reserved

        return $header . $content;
    }

    public static function stdout(string $body, int $requestId = 1): string
    {
        return self::buildRecord(6, $requestId, $body);
    }

    public static function stderr(string $body, int $requestId = 1): string
    {
        return self::buildRecord(7, $requestId, $body);
    }

    public static function endRequest(int $requestId = 1): string
    {
        return self::buildRecord(3, $requestId, str_repeat("\x00", 8));
    }

    public static function responseWithStdoutOnly(string $stdoutBody, int $requestId = 1): string
    {
        return self::stdout($stdoutBody, $requestId) . self::endRequest($requestId);
    }

    public static function responseWithStderr(string $stdoutBody, string $stderrBody, int $requestId = 1): string
    {
        return self::stdout($stdoutBody, $requestId)
             . self::stderr($stderrBody, $requestId)
             . self::endRequest($requestId);
    }
}
