<?php

declare(strict_types=1);

namespace Phalanx\Concurrency;

use RuntimeException;
use Throwable;

final readonly class Settlement
{
    private function __construct(
        public bool $isOk,
        public mixed $value,
        public ?Throwable $error,
    ) {
    }

    public static function ok(mixed $value): self
    {
        return new self(isOk: true, value: $value, error: null);
    }

    public static function err(Throwable $error): self
    {
        return new self(isOk: false, value: null, error: $error);
    }

    /**
     * @throws Throwable The original error if this settlement captured a failure
     */
    public function unwrap(): mixed
    {
        if (!$this->isOk) {
            throw $this->error ?? new RuntimeException('Settlement is in error state');
        }

        return $this->value;
    }

    public function unwrapOr(mixed $default): mixed
    {
        return $this->isOk ? $this->value : $default;
    }

    public function error(): ?Throwable
    {
        return $this->error;
    }
}
