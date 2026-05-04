<?php

declare(strict_types=1);

namespace Phalanx\Grammata;

use Phalanx\Scope\Suspendable;
use Phalanx\Supervisor\WaitReason;
use React\Promise\Deferred;

use function React\Async\await;

final class FilePool
{
    private int $active = 0;

    /** @var list<Deferred<null>> */
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

        /** @var Deferred<null> $deferred */
        $deferred = new Deferred();
        $this->waiters[] = $deferred;
        $scope->call(
            static fn(): mixed => await($deferred->promise()),
            WaitReason::custom('file.pool.acquire'),
        );
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
