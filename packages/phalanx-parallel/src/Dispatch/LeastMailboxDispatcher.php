<?php

declare(strict_types=1);

namespace Phalanx\Parallel\Dispatch;

use Phalanx\Parallel\Agent\AgentState;
use Phalanx\Parallel\Agent\Worker;
use Phalanx\Parallel\Protocol\TaskRequest;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

final class LeastMailboxDispatcher implements Dispatcher
{
    /**
     * @param list<Worker> $agents
     */
    public function __construct(
        private readonly array $agents,
    ) {
    }

    public function dispatch(TaskRequest $task): PromiseInterface
    {
        if (count($this->agents) === 0) {
            return reject(new \RuntimeException('No agents available'));
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
            return reject(new \RuntimeException('All agents unavailable'));
        }

        return $bestAgent->send($task);
    }
}
