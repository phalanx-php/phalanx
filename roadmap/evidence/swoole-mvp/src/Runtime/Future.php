<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Runtime;

use OpenSwoole\Coroutine\Channel;

final class Future
{
    private(set) bool $settled = false;

    private mixed $value = null;

    private ?\Throwable $error = null;

    private readonly Channel $waiters;

    public function __construct()
    {
        $this->waiters = new Channel(1);
    }

    public static function resolved(mixed $value): self
    {
        $f = new self();
        $f->settle($value);
        return $f;
    }

    public static function rejected(\Throwable $error): self
    {
        $f = new self();
        $f->fail($error);
        return $f;
    }

    public function settle(mixed $value): void
    {
        if ($this->settled) {
            return;
        }
        $this->value = $value;
        $this->settled = true;
        $this->waiters->close();
    }

    public function fail(\Throwable $error): void
    {
        if ($this->settled) {
            return;
        }
        $this->error = $error;
        $this->settled = true;
        $this->waiters->close();
    }

    public function wait(): mixed
    {
        if (! $this->settled) {
            $this->waiters->pop();
        }
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->value;
    }
}
