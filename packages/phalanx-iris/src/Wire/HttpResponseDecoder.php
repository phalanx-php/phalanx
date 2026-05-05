<?php

declare(strict_types=1);

namespace Phalanx\Iris\Wire;

use Phalanx\Iris\HttpClientException;
use Phalanx\Iris\HttpResponse;

/**
 * Parse a complete HTTP/1.1 response payload into a typed value object.
 *
 * Supports both `Content-Length`-bounded bodies and `Transfer-Encoding:
 * chunked` framing. Trailers after the final chunk are accepted but
 * not surfaced; chunked-encoding trailers are rarely consumed by
 * callers and add parser surface; if needed, a follow-up can expose
 * them through a separate accessor.
 */
final class HttpResponseDecoder
{
    public static function decode(string $payload): HttpResponse
    {
        $boundary = strpos($payload, "\r\n\r\n");
        if ($boundary === false) {
            throw new HttpClientException('HTTP response missing header/body boundary.');
        }

        $headerBlock = substr($payload, 0, $boundary);
        $bodyBlock = substr($payload, $boundary + 4);

        $lines = explode("\r\n", $headerBlock);
        if ($lines === [] || !isset($lines[0])) {
            throw new HttpClientException('HTTP response missing status line.');
        }

        $statusLine = array_shift($lines);
        [$protocol, $status, $reason] = self::parseStatusLine($statusLine);

        $headers = self::parseHeaders($lines);

        $transferEncoding = strtolower(self::firstHeader($headers, 'transfer-encoding') ?? '');
        if (str_contains($transferEncoding, 'chunked')) {
            $bodyBlock = self::decodeChunkedBody($bodyBlock);
        } else {
            $contentLength = self::firstHeader($headers, 'content-length');
            if ($contentLength !== null) {
                $expected = (int) $contentLength;
                if (strlen($bodyBlock) > $expected) {
                    $bodyBlock = substr($bodyBlock, 0, $expected);
                }
            }
        }

        return new HttpResponse(
            status: $status,
            reasonPhrase: $reason,
            headers: $headers,
            body: $bodyBlock,
            protocolVersion: $protocol,
        );
    }

    /** @return array{string, int, string} */
    private static function parseStatusLine(string $line): array
    {
        if (!preg_match('#^HTTP/(\d\.\d)\s+(\d{3})(?:\s+(.*))?$#', $line, $match)) {
            throw new HttpClientException("Invalid HTTP status line: {$line}");
        }

        return [$match[1], (int) $match[2], $match[3] ?? ''];
    }

    /**
     * @param list<string> $lines
     * @return array<string, list<string>>
     */
    private static function parseHeaders(array $lines): array
    {
        $headers = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                throw new HttpClientException("Invalid HTTP header line: {$line}");
            }

            $name = substr($line, 0, $colon);
            $value = ltrim(substr($line, $colon + 1));
            $headers[$name][] = $value;
        }

        return $headers;
    }

    /** @param array<string, list<string>> $headers */
    private static function firstHeader(array $headers, string $name): ?string
    {
        $key = strtolower($name);
        foreach ($headers as $h => $values) {
            if (strtolower($h) === $key && $values !== []) {
                return $values[0];
            }
        }

        return null;
    }

    private static function decodeChunkedBody(string $body): string
    {
        $decoded = '';
        $offset = 0;
        $length = strlen($body);

        while ($offset < $length) {
            $eol = strpos($body, "\r\n", $offset);
            if ($eol === false) {
                throw new HttpClientException('Malformed chunked encoding: missing chunk-size line terminator.');
            }

            $sizeLine = substr($body, $offset, $eol - $offset);
            $sizeHex = strtok($sizeLine, ';');
            if ($sizeHex === false || !preg_match('/^[0-9a-fA-F]+$/', $sizeHex)) {
                throw new HttpClientException("Malformed chunk size: {$sizeLine}");
            }

            $size = hexdec($sizeHex);
            $offset = $eol + 2;

            if ($size === 0) {
                return $decoded;
            }

            if ($offset + $size > $length) {
                throw new HttpClientException('Truncated chunk body.');
            }

            $decoded .= substr($body, $offset, (int) $size);
            $offset += (int) $size;

            if (substr($body, $offset, 2) !== "\r\n") {
                throw new HttpClientException('Missing CRLF after chunk data.');
            }

            $offset += 2;
        }

        throw new HttpClientException('Chunked body did not end with zero-length chunk.');
    }
}
