<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Collab\Contracts;

use Phalanx\Tui\Collab\Boundaries\Urgency;
use Phalanx\Tui\Collab\Effects\EffectStatus;
use Phalanx\Tui\Collab\Events\EventKind;
use Phalanx\Tui\Collab\Lifecycle\LoopStage;
use Phalanx\Tui\Collab\Messages\MessageKind;
use Phalanx\Tui\Collab\Plans\Activity;
use Phalanx\Tui\Collab\Plans\WorkItemStatus;
use Phalanx\Tui\Collab\Plans\WorkPlanStatus;
use Phalanx\Tui\Collab\Plans\WorkResultStatus;
use Phalanx\Tui\Collab\Reviews\ReviewStatus;
use Phalanx\Tui\Collab\State\TimelineEntryKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnumSurfaceTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, list<string>}>
     */
    public static function unorderedEnumValues(): iterable
    {
        yield 'message kind' => [MessageKind::class, [
            'prompt',
            'response',
            'tool_request',
            'tool_result',
            'task',
            'task_result',
            'order',
            'feedback',
            'observation',
            'alert',
            'approval',
            'denial',
            'plan_update',
            'status_update',
        ]];
        yield 'event kind' => [EventKind::class, [
            'loop_advanced',
            'work_received',
            'work_prepared',
            'work_distributed',
            'work_item_started',
            'effect_requested',
            'effect_approved',
            'effect_denied',
            'work_item_completed',
            'work_interrupted',
            'work_reviewed',
            'work_completed',
        ]];
        yield 'activity' => [Activity::class, [
            'thinking',
            'exploring',
            'researching',
            'editing',
            'testing',
            'reviewing',
        ]];
        yield 'effect status' => [EffectStatus::class, [
            'requested',
            'approved',
            'denied',
            'executing',
            'resolved',
            'failed',
        ]];
        yield 'work result status' => [WorkResultStatus::class, [
            'done',
            'blocked',
            'failed',
        ]];
        yield 'work item status' => [WorkItemStatus::class, [
            'pending',
            'running',
            'done',
            'blocked',
            'failed',
            'superseded',
        ]];
        yield 'work plan status' => [WorkPlanStatus::class, [
            'active',
            'suspended',
            'complete',
            'aborted',
        ]];
        yield 'review status' => [ReviewStatus::class, [
            'approved',
            'rejected',
            'needs_revision',
        ]];
        yield 'timeline entry kind' => [TimelineEntryKind::class, [
            'prompt',
            'response',
            'message',
            'work_started',
            'work_completed',
            'work_interrupted',
            'review',
        ]];
    }

    /**
     * @param class-string $enum
     * @param list<string> $values
     */
    #[Test]
    #[DataProvider('unorderedEnumValues')]
    public function enumValuesLockTheProtocolSurfaceWithoutOverspecifyingOrder(string $enum, array $values): void
    {
        $actual = array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases());
        sort($actual);
        sort($values);

        self::assertSame($values, $actual);
    }

    #[Test]
    public function loopStageOrderLocksTheExecutionFlow(): void
    {
        self::assertSame(
            [
                'receive',
                'prepare',
                'distribute',
                'execute',
                'react',
                'review',
                'complete',
            ],
            array_map(static fn (LoopStage $case): string => $case->value, LoopStage::cases()),
        );
    }

    #[Test]
    public function urgencyPrioritiesLockInboundSchedulingSemantics(): void
    {
        self::assertSame(0, Urgency::Queue->priority());
        self::assertSame(50, Urgency::Prioritize->priority());
        self::assertSame(100, Urgency::Interrupt->priority());
    }
}
