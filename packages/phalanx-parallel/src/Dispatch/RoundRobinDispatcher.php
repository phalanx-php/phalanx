<?php

declare(strict_types=1);

namespace Phalanx\Parallel\Dispatch;

use Phalanx\Parallel\Agent\AgentState;
use Phalanx\Parallel\Agent\Worker;
use Phalanx\Parallel\Protocol\TaskRequest;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

final class RoundRobinDispatcher implements Dispatcher
{
    private int $index = 0;

    /**
     * @param list<Worker> $agents
     */
    public function __construct(
        private readonly array $agents,
    ) {
    }

    /** @return PromiseInterface<mixed> */
    public function dispatch(TaskRequest $task): PromiseInterface
    {
        $count = count($this->agents);

        if ($count === 0) {
            return reject(new \RuntimeException('No agents available'));
        }

        $attempts = 0;

        while ($attempts < $count) {
            $agent = $this->agents[$this->index];
            $this->index = ($this->index + 1) % $count;
            $attempts++;

            if ($agent->state !== AgentState::Crashed && $agent->state !== AgentState::Draining) {
                return $agent->send($task);
            }
        }

        return reject(new \RuntimeException('All agents unavailable'));
    }
}
