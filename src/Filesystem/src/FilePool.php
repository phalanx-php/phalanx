<?php

declare(strict_types=1);

namespace Phalanx\Filesystem;

use Phalanx\Scope\Suspendable;
use Phalanx\Stream\Channel;
use Phalanx\Supervisor\WaitReason;
use RuntimeException;
use Throwable;

final class FilePool
{
    private(set) int $activeCount = 0;

    private(set) int $waitingCount = 0;

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
        if ($this->activeCount < $this->maxOpen) {
            $this->activeCount++;

            return;
        }

        $waiter = new Channel(bufferSize: 1);
        $this->waiters[] = $waiter;
        $this->waitingCount++;

        try {
            $scope->call(
                static fn(): mixed => $waiter->next(),
                WaitReason::custom('file.pool.acquire'),
            );
        } catch (Throwable $e) {
            $this->removeWaiter($waiter);

            throw $e;
        }
        $this->activeCount++;
    }

    public function release(): void
    {
        if ($this->activeCount < 1) {
            throw new RuntimeException('FilePool::release(): no active file slot to release');
        }

        $this->activeCount--;

        if ($this->waiters !== []) {
            $waiter = array_shift($this->waiters);
            $this->waitingCount--;
            $waiter->emit(true);
            $waiter->complete();
        }
    }

    private function removeWaiter(Channel $waiter): void
    {
        foreach ($this->waiters as $index => $candidate) {
            if ($candidate !== $waiter) {
                continue;
            }

            array_splice($this->waiters, $index, 1);
            $this->waitingCount--;
            $waiter->complete();

            return;
        }
    }
}
