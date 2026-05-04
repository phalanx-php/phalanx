<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Http\Client\Wire;

use Phalanx\Stoa\Http\Client\HttpClientException;
use Phalanx\Stoa\Http\Client\StoaHttpRequest;

/**
 * Encode a {@see StoaHttpRequest} into an HTTP/1.1 wire payload.
 *
 * The encoder normalizes header casing per RFC 7230 (case-insensitive on
 * input, single spelling on output), injects mandatory `Host:` derived
 * from the URL, sets `Content-Length:` automatically when a body is
 * present and the caller did not provide one, and writes a `Connection:
 * close` header by default — keep-alive callers override it explicitly.
 */
final class HttpRequestEncoder
{
    /** @return array{request: string, host: string, port: int, scheme: string, path: string, body: string|null} */
    public static function encode(StoaHttpRequest $request, ?string $userAgent = null): array
    {
        $parts = self::parseUrl($request->url);
        $headers = self::normalizeHeaders($request->headers);

        $headers['host'] ??= [$parts['host'] . self::nonDefaultPortSuffix($parts['scheme'], $parts['port'])];

        if ($userAgent !== null && !isset($headers['user-agent'])) {
            $headers['user-agent'] = [$userAgent];
        }

        if ($request->body !== null) {
            $headers['content-length'] = [(string) strlen($request->body)];
        }

        if (!isset($headers['connection'])) {
            $headers['connection'] = ['close'];
        }

        $line = sprintf("%s %s HTTP/1.1\r\n", strtoupper($request->method), $parts['path']);

        $headerLines = '';
        foreach ($headers as $name => $values) {
            $canonical = self::canonicalize($name);
            foreach ($values as $value) {
                $headerLines .= "{$canonical}: {$value}\r\n";
            }
        }

        return [
            'request' => $line . $headerLines . "\r\n" . ($request->body ?? ''),
            'host' => $parts['host'],
            'port' => $parts['port'],
            'scheme' => $parts['scheme'],
            'path' => $parts['path'],
            'body' => $request->body,
        ];
    }

    /** @return array{scheme: string, host: string, port: int, path: string} */
    private static function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || !isset($parts['scheme'])) {
            throw new HttpClientException("Invalid URL: {$url}");
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new HttpClientException("Unsupported scheme: {$scheme}");
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = $parts['path'] ?? '/';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        return [
            'scheme' => $scheme,
            'host' => (string) $parts['host'],
            'port' => (int) $port,
            'path' => $path,
        ];
    }

    private static function nonDefaultPortSuffix(string $scheme, int $port): string
    {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)
            ? ''
            : ':' . $port;
    }

    /**
     * @param array<string, list<string>> $headers
     * @return array<string, list<string>>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $key = strtolower($name);
            $normalized[$key] = array_values($values);
        }

        return $normalized;
    }

    private static function canonicalize(string $lowercased): string
    {
        return implode('-', array_map(static fn(string $part): string => ucfirst($part), explode('-', $lowercased)));
    }
}
