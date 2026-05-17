<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Transport;

use Phalanx\Panoply\Hash\Canonicalizable;

/**
 * Agent-side declaration of transport requirements. The transport selector
 * chooses an adapter whose declared capabilities satisfy these requirements.
 * `required` flags reject incompatible transports; `preferred` flags break
 * ties.
 *
 * Final because the canonical hash is load-bearing: subclassing would
 * alter toCanonical() and break hash stability across consumers.
 */
final class Needs implements Canonicalizable
{
    private function __construct(
        private(set) bool $streamingRequired = false,
        private(set) bool $cancellableRequired = false,
        private(set) bool $backpressurePreferred = false,
        private(set) bool $partialJsonPreferred = false,
        private(set) ?int $maxIdleSeconds = null,
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * @return array{
     *     streaming: bool,
     *     cancellable: bool,
     *     backpressure: bool,
     *     partial_json: bool,
     *     max_idle_seconds: int|null
     * }
     */
    final public function toCanonical(): array
    {
        return [
            'streaming'        => $this->streamingRequired,
            'cancellable'      => $this->cancellableRequired,
            'backpressure'     => $this->backpressurePreferred,
            'partial_json'     => $this->partialJsonPreferred,
            'max_idle_seconds' => $this->maxIdleSeconds,
        ];
    }

    public function streaming(): self
    {
        return new self(
            streamingRequired: true,
            cancellableRequired: $this->cancellableRequired,
            backpressurePreferred: $this->backpressurePreferred,
            partialJsonPreferred: $this->partialJsonPreferred,
            maxIdleSeconds: $this->maxIdleSeconds,
        );
    }

    public function cancellable(): self
    {
        return new self(
            streamingRequired: $this->streamingRequired,
            cancellableRequired: true,
            backpressurePreferred: $this->backpressurePreferred,
            partialJsonPreferred: $this->partialJsonPreferred,
            maxIdleSeconds: $this->maxIdleSeconds,
        );
    }

    public function preferBackpressure(): self
    {
        return new self(
            streamingRequired: $this->streamingRequired,
            cancellableRequired: $this->cancellableRequired,
            backpressurePreferred: true,
            partialJsonPreferred: $this->partialJsonPreferred,
            maxIdleSeconds: $this->maxIdleSeconds,
        );
    }

    public function preferPartialJson(): self
    {
        return new self(
            streamingRequired: $this->streamingRequired,
            cancellableRequired: $this->cancellableRequired,
            backpressurePreferred: $this->backpressurePreferred,
            partialJsonPreferred: true,
            maxIdleSeconds: $this->maxIdleSeconds,
        );
    }

    public function withMaxIdleSeconds(int $seconds): self
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('maxIdleSeconds must be at least 1');
        }

        return new self(
            streamingRequired: $this->streamingRequired,
            cancellableRequired: $this->cancellableRequired,
            backpressurePreferred: $this->backpressurePreferred,
            partialJsonPreferred: $this->partialJsonPreferred,
            maxIdleSeconds: $seconds,
        );
    }
}
