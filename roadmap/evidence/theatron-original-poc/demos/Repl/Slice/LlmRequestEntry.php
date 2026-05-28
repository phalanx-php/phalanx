<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Slice;

final class LlmRequestEntry
{
    public function __construct(
        private(set) string $requestId,
        private(set) string $method,
        private(set) string $path,
        private(set) ?int $status = null,
        private(set) ?float $elapsedMs = null,
        private(set) ?int $tokenCount = null,
        private(set) ?string $requestBody = null,
        private(set) ?string $responseBody = null,
        private(set) float $startTime = 0.0,
        private(set) bool $complete = false,
        private(set) ?string $error = null,
    ) {
    }

    public function markComplete(int $status, float $elapsedMs, int $tokenCount, string $responseBody): self
    {
        $clone = clone $this;
        $clone->status = $status;
        $clone->elapsedMs = $elapsedMs;
        $clone->tokenCount = $tokenCount;
        $clone->responseBody = $responseBody;
        $clone->complete = true;

        return $clone;
    }

    public function markError(string $error, float $elapsedMs): self
    {
        $clone = clone $this;
        $clone->error = $error;
        $clone->elapsedMs = $elapsedMs;
        $clone->complete = true;

        return $clone;
    }
}
