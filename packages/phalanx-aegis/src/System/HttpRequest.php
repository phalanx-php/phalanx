<?php

declare(strict_types=1);

namespace Phalanx\System;

/**
 * Outbound HTTP request value object.
 *
 * Immutable. Headers carry a flat map (case-insensitive lookup is the
 * caller's responsibility — OpenSwoole's HTTP/2 client lowercases
 * inbound header names per RFC 7540, but outbound headers pass through
 * as-is). Body is a string; binary payloads pass through unchanged.
 *
 * Builders chain via {@see withHeader()} and {@see withBody()}, each
 * returning a fresh instance so request objects are safely shareable
 * across coroutines.
 */
final readonly class HttpRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $body = '',
        public array $headers = [],
    ) {
    }

    /** @param array<string, string> $headers */
    public static function get(string $path, array $headers = []): self
    {
        return new self('GET', $path, '', $headers);
    }

    /** @param array<string, string> $headers */
    public static function post(string $path, string $body = '', array $headers = []): self
    {
        return new self('POST', $path, $body, $headers);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;
        return new self($this->method, $this->path, $this->body, $headers);
    }

    public function withBody(string $body): self
    {
        return new self($this->method, $this->path, $body, $this->headers);
    }
}
