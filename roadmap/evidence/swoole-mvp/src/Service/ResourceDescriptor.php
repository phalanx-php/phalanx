<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Service;

final class ResourceDescriptor
{
    /** @var \Closure():object|null */
    private(set) ?\Closure $factory = null;

    private(set) ?int $capacity = null;

    private(set) bool $transactionSafe = false;

    private(set) bool $suspending = false;

    private(set) string $keyspace = 'worker';

    /**
     * @param class-string $class
     */
    public function __construct(public readonly string $class) {}

    public function factory(\Closure $factory): self
    {
        $this->factory = $factory;
        return $this;
    }

    public function capacity(int $n): self
    {
        $this->capacity = $n;
        return $this;
    }

    public function transactionSafe(): self
    {
        $this->transactionSafe = true;
        return $this;
    }

    public function suspending(): self
    {
        $this->suspending = true;
        return $this;
    }

    public function keyspace(string $name): self
    {
        $this->keyspace = $name;
        return $this;
    }
}
