<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Template\Slice;

use InvalidArgumentException;
use Phalanx\Harness\Template\Slice\ActivitySlice;
use Phalanx\Harness\Template\Slice\ActivityStatus;
use Phalanx\Harness\Template\Slice\PendingEffect;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActivitySliceTest extends TestCase
{
    #[Test]
    public function defaultStateIsIdleWithZeroTokens(): void
    {
        $slice = new ActivitySlice();

        self::assertSame(ActivityStatus::Idle, $slice->status);
        self::assertNull($slice->pendingEffect);
        self::assertSame(0, $slice->inputTokens);
        self::assertSame(0, $slice->outputTokens);
        self::assertSame(0, $slice->totalTokens);
    }

    #[Test]
    public function awaitingApprovalSetsStatusAndStoresEffect(): void
    {
        $effect = new PendingEffect(
            kind: 'file_write',
            summary: 'Write to /etc/hosts',
            arguments: ['path' => '/etc/hosts'],
            hazardLevel: 3,
        );

        $slice = new ActivitySlice()->awaitingApproval($effect);

        self::assertSame(ActivityStatus::AwaitingApproval, $slice->status);
        self::assertSame($effect, $slice->pendingEffect);
    }

    #[Test]
    public function effectResolvedClearsEffectAndSetsRunning(): void
    {
        $effect = new PendingEffect(
            kind: 'shell',
            summary: 'Run deploy script',
            arguments: [],
            hazardLevel: 2,
        );

        $slice = new ActivitySlice()
            ->awaitingApproval($effect)
            ->effectResolved();

        self::assertSame(ActivityStatus::Running, $slice->status);
        self::assertNull($slice->pendingEffect);
    }

    #[Test]
    public function activityEndedWithCompletedSetsTerminalState(): void
    {
        $slice = new ActivitySlice()->activityEnded(ActivityStatus::Completed);

        self::assertSame(ActivityStatus::Completed, $slice->status);
    }

    #[Test]
    public function activityEndedWithFailedSetsTerminalState(): void
    {
        $slice = new ActivitySlice()->activityEnded(ActivityStatus::Failed);

        self::assertSame(ActivityStatus::Failed, $slice->status);
    }

    #[Test]
    public function activityEndedWithCancelledSetsTerminalState(): void
    {
        $slice = new ActivitySlice()->activityEnded(ActivityStatus::Cancelled);

        self::assertSame(ActivityStatus::Cancelled, $slice->status);
    }

    #[Test]
    public function activityEndedRejectsIdleAsNonTerminal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a terminal state');

        new ActivitySlice()->activityEnded(ActivityStatus::Idle);
    }

    #[Test]
    public function activityEndedRejectsRunningAsNonTerminal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a terminal state');

        new ActivitySlice()->activityEnded(ActivityStatus::Running);
    }

    #[Test]
    public function activityEndedRejectsAwaitingApprovalAsNonTerminal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a terminal state');

        new ActivitySlice()->activityEnded(ActivityStatus::AwaitingApproval);
    }

    #[Test]
    public function updateUsageIncrementsTokenCounters(): void
    {
        $slice = new ActivitySlice()
            ->updateUsage(100, 200);

        self::assertSame(100, $slice->inputTokens);
        self::assertSame(200, $slice->outputTokens);
        self::assertSame(300, $slice->totalTokens);
    }

    #[Test]
    public function updateUsageAccumulates(): void
    {
        $slice = new ActivitySlice()
            ->updateUsage(100, 200)
            ->updateUsage(50, 75);

        self::assertSame(150, $slice->inputTokens);
        self::assertSame(275, $slice->outputTokens);
        self::assertSame(425, $slice->totalTokens);
    }

    #[Test]
    public function sliceIsCopyOnModify(): void
    {
        $original = new ActivitySlice();
        $modified = $original->updateUsage(10, 20);

        self::assertSame(0, $original->inputTokens);
        self::assertSame(10, $modified->inputTokens);
        self::assertNotSame($original, $modified);
    }
}
