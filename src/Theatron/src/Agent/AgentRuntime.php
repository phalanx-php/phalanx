<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Agent;

use Closure;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskScope;
use Phalanx\Supervisor\TaskHandle;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Slice\ActivityStatus;
use Phalanx\Theatron\Template\Slice\PendingEffect;

final class AgentRuntime
{
    private ?TaskHandle $currentAgentTask = null;

    public function __construct(
        private(set) AppStore $store,
        private(set) AgentExecutorContract $executor,
    ) {
    }

    public function send(TaskScope $scope, string $message): void
    {
        $store = $this->store;
        $executor = $this->executor;

        $this->spawnOrRun($scope, static function () use ($scope, $store, $executor, $message): void {
            self::consume($executor->send($message), $store);
            self::runQueued($scope, $store, $executor);
        }, 'theatron-agent-send');
    }

    public function approve(TaskScope $scope, ?PendingEffect $effect = null): void
    {
        $effect ??= $this->store->activity->pendingEffect;

        if ($effect === null) {
            return;
        }

        $store = $this->store;
        $executor = $this->executor;
        $store->activity = $store->activity->effectResolved();

        $this->spawnOrRun($scope, static function () use ($scope, $store, $executor, $effect): void {
            self::consume($executor->approve($effect), $store);
            self::runQueued($scope, $store, $executor);
        }, 'theatron-agent-approve');
    }

    public function deny(TaskScope $scope, ?PendingEffect $effect = null): void
    {
        $effect ??= $this->store->activity->pendingEffect;

        if ($effect === null) {
            return;
        }

        $store = $this->store;
        $executor = $this->executor;
        $this->currentAgentTask?->cancel();
        $this->currentAgentTask = null;

        $this->spawnOrRun($scope, static function () use ($store, $executor, $effect): void {
            self::consume($executor->deny($effect), $store);
            $store->activity = $store->activity
                ->effectResolved()
                ->activityEnded(ActivityStatus::Cancelled);
        }, 'theatron-agent-deny');
    }

    /** @param iterable<\Phalanx\Panoply\Cue> $cues */
    private static function consume(iterable $cues, AppStore $store): void
    {
        StreamReactor::consume($cues, $store);
    }

    private static function runQueued(
        TaskScope $scope,
        AppStore $store,
        AgentExecutorContract $executor,
    ): void {
        while (!$store->activity->isBusy() && ($message = $store->input->peek()) !== null) {
            $store->input = $store->input->dequeue();
            $store->conversation = $store->conversation->addUserMessage($message);
            $store->activity = $store->activity->withStatus(ActivityStatus::Running);

            self::consume($executor->send($message), $store);
        }
    }

    /** @param Closure(): void $task */
    private function spawnOrRun(TaskScope $scope, Closure $task, string $name): void
    {
        if ($scope instanceof ExecutionScope) {
            $this->currentAgentTask = $scope->go($task, $name);

            return;
        }

        $scope->execute($task);
    }
}
