<?php

declare(strict_types=1);

namespace Phalanx\Grammata;

use Phalanx\Scope\Suspendable;
use Phalanx\Styx\Channel;
use Phalanx\Supervisor\WaitReason;
use RuntimeException;
use Throwable;

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
        if ($this->maxOpen < 1) {
            throw new RuntimeException('FilePool requires maxOpen >= 1');
        }
    }

    public function acquire(Suspendable $scope): void
    {
        if ($this->active < $this->maxOpen) {
            $this->active++;
            return;
        }

        $waiter = new Channel(bufferSize: 1);
        $this->waiters[] = $waiter;
        try {
            $scope->call(
                static fn(): mixed => $waiter->next(),
                WaitReason::custom('file.pool.acquire'),
            );
        } catch (Throwable $e) {
            $this->removeWaiter($waiter);
            throw $e;
        }
        $this->active++;
    }

    public function release(): void
    {
        if ($this->active < 1) {
            throw new RuntimeException('FilePool::release(): no active file slot to release');
        }

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

    private function removeWaiter(Channel $waiter): void
    {
        foreach ($this->waiters as $index => $candidate) {
            if ($candidate !== $waiter) {
                continue;
            }

            array_splice($this->waiters, $index, 1);
            $waiter->complete();
            return;
        }
    }
}
