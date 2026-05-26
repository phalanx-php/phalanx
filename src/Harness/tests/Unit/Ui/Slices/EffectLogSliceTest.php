<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Ui\Slices;

use Phalanx\Harness\Ui\Slices\EffectLogSlice;
use Phalanx\Harness\Ui\Slices\EffectStatus;
use Phalanx\Harness\Ui\Slices\PendingEffect;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EffectLogSliceTest extends TestCase
{
    #[Test]
    public function approvedEffectKeepsGrantWhenExecutionArrives(): void
    {
        $slice = $this->requested()
            ->mark('eff_1', EffectStatus::Approved, grantId: 'grant_1')
            ->mark('eff_1', EffectStatus::Executed, durationMs: 42);

        $entry = $slice->entries[0];

        self::assertSame(EffectStatus::Executed, $entry->status);
        self::assertSame('grant_1', $entry->grantId);
        self::assertSame(42, $entry->durationMs);
        self::assertNull($entry->errorClass);
    }

    #[Test]
    public function terminalStatusDetailsDoNotLeakAcrossLaterStatuses(): void
    {
        $executedAfterFailure = $this->requested()
            ->mark('eff_1', EffectStatus::Failed, reasonCodes: ['boom'], errorClass: 'RuntimeException')
            ->mark('eff_1', EffectStatus::Executed, durationMs: 42);

        self::assertSame(EffectStatus::Executed, $executedAfterFailure->entries[0]->status);
        self::assertSame(42, $executedAfterFailure->entries[0]->durationMs);
        self::assertNull($executedAfterFailure->entries[0]->errorClass);

        $failedAfterExecution = $this->requested()
            ->mark('eff_1', EffectStatus::Executed, durationMs: 42)
            ->mark('eff_1', EffectStatus::Failed, reasonCodes: ['boom'], errorClass: 'RuntimeException');

        self::assertSame(EffectStatus::Failed, $failedAfterExecution->entries[0]->status);
        self::assertNull($failedAfterExecution->entries[0]->durationMs);
        self::assertSame('RuntimeException', $failedAfterExecution->entries[0]->errorClass);
    }

    private function requested(): EffectLogSlice
    {
        return (new EffectLogSlice())->appendRequested(new PendingEffect(
            kind: 'file.read',
            summary: 'Read a strategy note',
            arguments: ['path' => 'notes/strategy.md'],
            hazardLevel: 1,
            effectId: 'eff_1',
            hazard: 'low',
        ));
    }
}
