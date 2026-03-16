<?php

declare(strict_types=1);

namespace Convoy\Parallel\Dispatch;

use Convoy\Parallel\Agent\AgentState;
use Convoy\Parallel\Agent\Worker;
use Convoy\Parallel\Protocol\TaskRequest;
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

    public function dispatch(TaskRequest $task): PromiseInterface
    {
        $count = count($this->agents);

        if ($count === 0) {
            return reject(new \RuntimeException('No agents available'));
        }

        $startIndex = $this->index;
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
