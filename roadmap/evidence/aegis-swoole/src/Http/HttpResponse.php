<?php

declare(strict_types=1);

namespace AegisSwoole\Http;

final readonly class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $headers,
    ) {
    }

    public function ok(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
