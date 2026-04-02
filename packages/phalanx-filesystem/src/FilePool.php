<?php

declare(strict_types=1);

namespace Phalanx\Filesystem;

use Phalanx\Suspendable;
use React\Promise\Deferred;

final class FilePool
{
    private int $active = 0;

    /** @var list<Deferred> */
    private array $waiters = [];

    public function __construct(
        private readonly int $maxOpen = 64,
    ) {}

    public function acquire(Suspendable $scope): void
    {
        if ($this->active < $this->maxOpen) {
            $this->active++;
            return;
        }

        $deferred = new Deferred();
        $this->waiters[] = $deferred;
        $scope->await($deferred->promise());
        $this->active++;
    }

    public function release(): void
    {
        $this->active--;

        if ($this->waiters !== []) {
            $deferred = array_shift($this->waiters);
            $deferred->resolve(null);
        }
    }

    public int $activeCount { get => $this->active; }

    public int $waitingCount { get => count($this->waiters); }
}
