<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Dispatch;

use Phalanx\Hydra\Agent\AgentState;
use Phalanx\Hydra\Agent\Worker;
use Phalanx\Hydra\Protocol\TaskRequest;
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

    /** @return PromiseInterface<mixed> */
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
