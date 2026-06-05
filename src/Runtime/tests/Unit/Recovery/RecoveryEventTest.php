<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Recovery;

use Phalanx\Mark\Mark;
use Phalanx\Recovery\RecoveryEvent;
use Phalanx\Recovery\RecoveryEventKind;
use Phalanx\Recovery\RecoveryPlan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RecoveryEventTest extends TestCase
{
    #[Test]
    public function propertiesExposed(): void
    {
        $elapsed = Mark::ms(500);
        $remaining = Mark::s(9);
        $error = new RuntimeException('boom');
        $plan = RecoveryPlan::defaultRetry();

        $event = new RecoveryEvent(
            kind: RecoveryEventKind::AttemptFailed,
            attempt: 2,
            elapsed: $elapsed,
            remainingDeadline: $remaining,
            error: $error,
            taskName: 'fetch-profile',
            plan: $plan,
        );

        self::assertSame(RecoveryEventKind::AttemptFailed, $event->kind);
        self::assertSame(2, $event->attempt);
        self::assertTrue($event->elapsed->eq($elapsed));
        self::assertNotNull($event->remainingDeadline);
        self::assertTrue($event->remainingDeadline->eq($remaining));
        self::assertSame($error, $event->error);
        self::assertSame('fetch-profile', $event->taskName);
        self::assertSame($plan, $event->plan);
    }

    #[Test]
    public function nullableFieldsAcceptNull(): void
    {
        $event = new RecoveryEvent(
            kind: RecoveryEventKind::AttemptStarted,
            attempt: 1,
            elapsed: Mark::zero(),
            remainingDeadline: null,
            error: null,
            taskName: 'test',
            plan: RecoveryPlan::none(),
        );

        self::assertNull($event->remainingDeadline);
        self::assertNull($event->error);
    }

    #[Test]
    public function allEventKindsInstantiable(): void
    {
        foreach (RecoveryEventKind::cases() as $kind) {
            $event = new RecoveryEvent(
                kind: $kind,
                attempt: 1,
                elapsed: Mark::zero(),
                remainingDeadline: null,
                error: null,
                taskName: 'test',
                plan: RecoveryPlan::none(),
            );

            self::assertSame($kind, $event->kind);
        }
    }
}
