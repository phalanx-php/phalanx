<?php

declare(strict_types=1);

namespace Convoy\Parallel\Agent;

use Convoy\Parallel\Protocol\TaskRequest;
use React\Promise\Deferred;
use SplQueue;

final class Mailbox
{
    /** @var SplQueue<array{TaskRequest, Deferred}> */
    private SplQueue $queue;

    public function __construct(
        private readonly int $limit = 100,
    ) {
        $this->queue = new SplQueue();
    }

    public function enqueue(TaskRequest $task, Deferred $deferred): void
    {
        if ($this->isFull()) {
            throw new \OverflowException("Mailbox full (limit: {$this->limit})");
        }

        $this->queue->enqueue([$task, $deferred]);
    }

    /**
     * @return array{TaskRequest, Deferred}
     */
    public function dequeue(): array
    {
        if ($this->isEmpty()) {
            throw new \UnderflowException('Mailbox empty');
        }

        return $this->queue->dequeue();
    }

    public function isEmpty(): bool
    {
        return $this->queue->isEmpty();
    }

    public function isFull(): bool
    {
        return $this->queue->count() >= $this->limit;
    }

    public function count(): int
    {
        return $this->queue->count();
    }

    public function rejectAll(\Throwable $reason): void
    {
        while (!$this->isEmpty()) {
            [, $deferred] = $this->dequeue();
            $deferred->reject($reason);
        }
    }
}
