<?php

declare(strict_types=1);

namespace Phalanx\System;

/**
 * Buffered HTTP response. The body is fully materialized in memory.
 *
 * For streaming consumption (token-by-token SSE / NDJSON), use
 * {@see HttpClient::stream()} which returns an {@see HttpStream} that
 * reads incrementally instead of buffering the full body.
 */
final class HttpResponse
{
    public bool $successful {
        get => $this->status >= 200 && $this->status < 300;
    }

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        $key = strtolower($name);
        foreach ($this->headers as $hname => $hvalue) {
            if (strtolower($hname) === $key) {
                return $hvalue;
            }
        }
        return null;
    }
}
