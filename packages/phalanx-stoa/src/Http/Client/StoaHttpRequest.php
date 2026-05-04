<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Http\Client;

/**
 * @phpstan-type HeaderMap array<string, list<string>>
 */
final readonly class StoaHttpRequest
{
    /** @param HeaderMap $headers */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public ?string $body = null,
        public float $connectTimeout = 5.0,
        public float $readTimeout = 30.0,
    ) {
    }

    /** @param HeaderMap $headers */
    public static function get(string $url, array $headers = []): self
    {
        return new self('GET', $url, $headers);
    }

    /** @param HeaderMap $headers */
    public static function post(string $url, string $body, array $headers = []): self
    {
        return new self('POST', $url, $headers, $body);
    }
}
