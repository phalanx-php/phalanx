<?php

declare(strict_types=1);

namespace Phalanx\Harness\Agent;

use Closure;
use Phalanx\Agora\Harness\CueRecorder;
use Phalanx\Harness\Ui\AppStore;
use Phalanx\Harness\Ui\Slices\ActivityStatus;
use Phalanx\Harness\Ui\Slices\PendingEffect;
use Phalanx\Panoply\Cue;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskScope;
use Phalanx\Supervisor\TaskHandle;

final class AgentRuntime
{
    private ?TaskHandle $currentAgentTask = null;

    public function __construct(
        private AppStore $store,
        private AgentExecutorContract $executor,
        private ?CueRecorder $recorder = null,
        private ?string $sessionId = null,
    ) {
    }

    public function send(TaskScope $scope, string $message): void
    {
        $store = $this->store;
        $executor = $this->executor;
        $recorder = $this->recorder;
        $sessionId = $this->sessionId;

        $this->spawnOrRun($scope, static function () use ($scope, $store, $executor, $message, $recorder, $sessionId): void {
            self::consume($executor->send($message), $store, $recorder, $sessionId);
            self::runQueued($scope, $store, $executor, $recorder, $sessionId);
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
        $recorder = $this->recorder;
        $sessionId = $this->sessionId;
        $store->activity = $store->activity->effectResolved();

        $this->spawnOrRun($scope, static function () use ($scope, $store, $executor, $effect, $recorder, $sessionId): void {
            self::consume($executor->approve($effect), $store, $recorder, $sessionId);
            self::runQueued($scope, $store, $executor, $recorder, $sessionId);
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
        $recorder = $this->recorder;
        $sessionId = $this->sessionId;
        $this->currentAgentTask?->cancel();
        $this->currentAgentTask = null;

        $this->spawnOrRun($scope, static function () use ($store, $executor, $effect, $recorder, $sessionId): void {
            self::consume($executor->deny($effect), $store, $recorder, $sessionId);
            $store->activity = $store->activity
                ->effectResolved()
                ->activityEnded(ActivityStatus::Cancelled);
        }, 'theatron-agent-deny');
    }

    /** @param iterable<Cue> $cues */
    private static function consume(
        iterable $cues,
        AppStore $store,
        ?CueRecorder $recorder,
        ?string $sessionId,
    ): void {
        foreach ($cues as $cue) {
            if ($recorder !== null && $sessionId !== null) {
                $recorder->record($cue, $sessionId, null);
            }

            StreamReactor::dispatch($cue, $store);
        }
    }

    private static function runQueued(
        TaskScope $scope,
        AppStore $store,
        AgentExecutorContract $executor,
        ?CueRecorder $recorder,
        ?string $sessionId,
    ): void {
        while (!$store->activity->isBusy() && ($message = $store->input->peek()) !== null) {
            $store->input = $store->input->dequeue();
            $store->conversation = $store->conversation->addUserMessage($message);
            $store->workspaceView = $store->workspaceView->startChatTurn();
            $store->activity = $store->activity->withStatus(ActivityStatus::Running);

            self::consume($executor->send($message), $store, $recorder, $sessionId);
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
