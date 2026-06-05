<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Transport;

use Phalanx\AiProviders\Hash\Canonicalizable;

/**
 * Final — canonical hash determinism: subclassing would alter toCanonical()
 * and break request-replay/audit stability across adapters.
 */
final class Request implements Canonicalizable
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private(set) string $method,
        private(set) string $url,
        private(set) array $headers = [],
        private(set) string $body = '',
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function of(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
    ): self {
        return new self($method, $url, $headers, $body);
    }

    /**
     * @return array{method: string, url: string, headers: array<string, string>, body: string}
     */
    public function toCanonical(): array
    {
        $headers = $this->headers;
        ksort($headers);

        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => $headers,
            'body' => $this->body,
        ];
    }
}
