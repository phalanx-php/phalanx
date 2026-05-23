<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Agent;

use Closure;
use DateTimeImmutable;
use Phalanx\Panoply\Cue\Activity\Completed as ActivityCompleted;
use Phalanx\Panoply\Cue\Activity\Started as ActivityStarted;
use Phalanx\Panoply\Cue\Effect\Denied as EffectDenied;
use Phalanx\Panoply\Cue\Effect\Executed as EffectExecuted;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\TaskHandle;
use Phalanx\Theatron\Agent\AgentRuntime;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Slice\ActivitySlice;
use Phalanx\Theatron\Template\Slice\ActivityStatus;
use Phalanx\Theatron\Template\Slice\EffectStatus;
use Phalanx\Theatron\Template\Slice\PendingEffect;
use Phalanx\Theatron\Tests\Support\RecordingAgentExecutor;
use Phalanx\Theatron\Tests\Support\RecordingTaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentRuntimeTest extends TestCase
{
    #[Test]
    public function denyCancelsCurrentAgentTaskAndClearsPendingEffect(): void
    {
        $cancelled = false;
        $scope = $this->createStub(ExecutionScope::class);
        $scope->method('go')->willReturnCallback(
            static function (Closure $task, ?string $name) use (&$cancelled): TaskHandle {
                if ($name === 'theatron-agent-send') {
                    return new TaskHandle(
                        id: 'send',
                        name: 'send',
                        cancel: static function () use (&$cancelled): void {
                            $cancelled = true;
                        },
                        snapshot: static fn(): null => null,
                    );
                }

                $task();

                return new TaskHandle(
                    id: 'runtime',
                    name: $name ?? 'runtime',
                    cancel: static function (): void {
                    },
                    snapshot: static fn(): null => null,
                );
            },
        );

        $effect = new PendingEffect(
            kind: 'process.run',
            summary: 'Run a command',
            arguments: [],
            hazardLevel: 3,
            effectId: 'effect-1',
        );
        $at = new DateTimeImmutable();
        $executor = new RecordingAgentExecutor(denialCues: [
            new EffectDenied('cue-deny', 1, 'activity-1', null, null, $at, 'effect-1', ['user-denied']),
        ]);
        $store = new AppStore();
        $store->effects = $store->effects->appendRequested($effect);
        $runtime = new AgentRuntime($store, $executor);

        $runtime->send($scope, 'hold the line');
        $store->activity = new ActivitySlice(pendingEffect: $effect);

        $runtime->deny($scope);

        self::assertTrue($cancelled);
        self::assertSame([$effect], $executor->deniedEffects);
        self::assertNull($store->activity->pendingEffect);
        self::assertSame(ActivityStatus::Cancelled, $store->activity->status);
        self::assertSame(EffectStatus::Denied, $store->effects->entries[0]->status);
        self::assertSame(['user-denied'], $store->effects->entries[0]->reasonCodes);
    }

    #[Test]
    public function approveConsumesApprovalCuesAndRunsQueuedMessagesAfterActivityCompletes(): void
    {
        $at = new DateTimeImmutable();
        $effect = new PendingEffect(
            kind: 'file.write',
            summary: 'Write a plan',
            arguments: [],
            hazardLevel: 2,
            effectId: 'effect-approve',
        );
        $executor = new RecordingAgentExecutor(
            sendCues: [
                new ActivityStarted('cue-send-start', 3, 'activity-queued', null, null, $at),
                new ActivityCompleted('cue-send-done', 4, 'activity-queued', null, null, $at),
            ],
            approvalCues: [
                new EffectExecuted('cue-executed', 1, 'activity-1', null, null, $at, 'effect-approve', 25),
                new ActivityCompleted('cue-approved-done', 2, 'activity-1', null, null, $at),
            ],
        );
        $store = new AppStore();
        $store->effects = $store->effects->appendRequested($effect);
        $store->activity = new ActivitySlice(
            status: ActivityStatus::AwaitingApproval,
            pendingEffect: $effect,
        );
        $store->input = $store->input->enqueue('follow-up message');

        new AgentRuntime($store, $executor)->approve(new RecordingTaskScope());

        self::assertSame([$effect], $executor->approvedEffects);
        self::assertSame(['follow-up message'], $executor->sentMessages);
        self::assertNull($store->input->peek());
        self::assertNull($store->activity->pendingEffect);
        self::assertSame(ActivityStatus::Completed, $store->activity->status);
        self::assertSame(EffectStatus::Executed, $store->effects->entries[0]->status);
        self::assertSame(25, $store->effects->entries[0]->durationMs);
        self::assertSame('follow-up message', $store->conversation->messages[0]->text);
    }
}
