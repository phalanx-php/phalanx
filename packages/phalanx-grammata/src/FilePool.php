<?php

declare(strict_types=1);

namespace Phalanx\Grammata;

use Phalanx\Scope\Suspendable;
use Phalanx\Styx\Channel;
use Phalanx\Supervisor\WaitReason;

final class FilePool
{
    public int $activeCount {
        get {
            return $this->active;
        }
    }

    public int $waitingCount {
        get {
            return $this->waiterCount();
        }
    }

    private int $active = 0;

    /** @var list<Channel> */
    private array $waiters = [];

    public function __construct(
        private readonly int $maxOpen = 64,
    ) {
    }

    public function acquire(Suspendable $scope): void
    {
        if ($this->active < $this->maxOpen) {
            $this->active++;
            return;
        }

        $waiter = new Channel(bufferSize: 1);
        $this->waiters[] = $waiter;
        $scope->call(
            static fn(): mixed => $waiter->next(),
            WaitReason::custom('file.pool.acquire'),
        );
        $this->active++;
    }

    public function release(): void
    {
        $this->active--;

        if ($this->waiters !== []) {
            $waiter = array_shift($this->waiters);
            $waiter->emit(true);
            $waiter->complete();
        }
    }

    private function waiterCount(): int
    {
        return count($this->waiters);
    }
}
