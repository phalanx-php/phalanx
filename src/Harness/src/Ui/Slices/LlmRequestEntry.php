<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Slices;

class LlmRequestEntry
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
        private(set) ?string $invocationId = null,
    ) {
    }

    public function markComplete(
        int $status,
        float $elapsedMs,
        int $tokenCount,
        string $responseBody,
    ): self {
        return new self(
            requestId: $this->requestId,
            method: $this->method,
            path: $this->path,
            status: $status,
            elapsedMs: $elapsedMs,
            tokenCount: $tokenCount,
            requestBody: $this->requestBody,
            responseBody: $responseBody,
            startTime: $this->startTime,
            complete: true,
            error: null,
            invocationId: $this->invocationId,
        );
    }

    public function markError(string $error, float $elapsedMs): self
    {
        return new self(
            requestId: $this->requestId,
            method: $this->method,
            path: $this->path,
            status: $this->status,
            elapsedMs: $elapsedMs,
            tokenCount: $this->tokenCount,
            requestBody: $this->requestBody,
            responseBody: $this->responseBody,
            startTime: $this->startTime,
            complete: true,
            error: $error,
            invocationId: $this->invocationId,
        );
    }

    public function withResponseBody(string $responseBody): self
    {
        return new self(
            requestId: $this->requestId,
            method: $this->method,
            path: $this->path,
            status: $this->status,
            elapsedMs: $this->elapsedMs,
            tokenCount: $this->tokenCount,
            requestBody: $this->requestBody,
            responseBody: $responseBody,
            startTime: $this->startTime,
            complete: $this->complete,
            error: $this->error,
            invocationId: $this->invocationId,
        );
    }

    public function withTokenCount(int $tokenCount): self
    {
        return new self(
            requestId: $this->requestId,
            method: $this->method,
            path: $this->path,
            status: $this->status,
            elapsedMs: $this->elapsedMs,
            tokenCount: $tokenCount,
            requestBody: $this->requestBody,
            responseBody: $this->responseBody,
            startTime: $this->startTime,
            complete: $this->complete,
            error: $this->error,
            invocationId: $this->invocationId,
        );
    }
}
