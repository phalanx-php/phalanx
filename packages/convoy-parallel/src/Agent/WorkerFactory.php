<?php

declare(strict_types=1);

namespace Convoy\Parallel\Agent;

use Convoy\Parallel\Process\ProcessConfig;
use React\EventLoop\LoopInterface;

final class WorkerFactory
{
    public function __construct(
        private readonly ProcessConfig $config,
        private readonly LoopInterface $loop,
        private readonly int $defaultMailboxLimit = 100,
    ) {
    }

    public function create(?string $id = null, ?int $mailboxLimit = null): Worker
    {
        return new Worker(
            config: $this->config,
            loop: $this->loop,
            mailboxLimit: $mailboxLimit ?? $this->defaultMailboxLimit,
            id: $id,
        );
    }

    /**
     * @return list<Worker>
     */
    public function createPool(int $count, ?int $mailboxLimit = null): array
    {
        $agents = [];

        for ($i = 0; $i < $count; $i++) {
            $agents[] = $this->create(
                id: sprintf('agent-%d', $i),
                mailboxLimit: $mailboxLimit,
            );
        }

        return $agents;
    }
}
