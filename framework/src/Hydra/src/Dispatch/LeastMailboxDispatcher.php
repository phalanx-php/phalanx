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

final class LeastMailboxDispatcher implements Dispatcher
{
    /**
     * @param list<Worker> $agents
     */
    public function __construct(
        private readonly array $agents,
    ) {
    }

    public function dispatch(TaskRequest $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
    {
        if (count($this->agents) === 0) {
            throw new RuntimeException('No agents available');
        }

        $bestAgent = null;
        $bestSize = PHP_INT_MAX;

        foreach ($this->agents as $agent) {
            if ($agent->state === AgentState::Crashed || $agent->state === AgentState::Draining) {
                continue;
            }

            $size = $agent->mailboxSize();

            if ($size < $bestSize) {
                $bestSize = $size;
                $bestAgent = $agent;
            }
        }

        if ($bestAgent === null) {
            throw new RuntimeException('All agents unavailable');
        }

        return $bestAgent->send($task, $scope, $token);
    }
}
