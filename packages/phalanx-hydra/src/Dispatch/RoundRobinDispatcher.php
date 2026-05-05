<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Dispatch;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Hydra\Agent\AgentState;
use Phalanx\Hydra\Agent\Worker;
use Phalanx\Hydra\Protocol\TaskRequest;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use RuntimeException;

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

    public function dispatch(TaskRequest $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
    {
        $count = count($this->agents);

        if ($count === 0) {
            throw new RuntimeException('No agents available');
        }

        $attempts = 0;

        while ($attempts < $count) {
            $agent = $this->agents[$this->index];
            $this->index = ($this->index + 1) % $count;
            $attempts++;

            if ($agent->state !== AgentState::Crashed && $agent->state !== AgentState::Draining) {
                return $agent->send($task, $scope, $token);
            }
        }

        throw new RuntimeException('All agents unavailable');
    }
}
