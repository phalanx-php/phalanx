<?php

declare(strict_types=1);

namespace Phalanx\Concurrency;

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
        return new self(true, $value, null);
    }

    public static function err(Throwable $error): self
    {
        return new self(false, null, $error);
    }

    public function unwrap(): mixed
    {
        if ($this->isOk) {
            return $this->value;
        }
        if ($this->error === null) {
            throw new \LogicException('Failed settlement is missing an error.');
        }

        throw $this->error;
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
