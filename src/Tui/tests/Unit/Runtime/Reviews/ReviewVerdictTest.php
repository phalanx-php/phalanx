<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Runtime\Reviews;

use Phalanx\Tui\Runtime\Plans\Activity;
use Phalanx\Tui\Runtime\Plans\WorkItem;
use Phalanx\Tui\Runtime\Reviews\ReviewStatus;
use Phalanx\Tui\Runtime\Reviews\ReviewVerdict;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReviewVerdictTest extends TestCase
{
    #[Test]
    public function approveVerdictCarriesNoRequiredWork(): void
    {
        $verdict = ReviewVerdict::approve();

        self::assertSame(ReviewStatus::Approved, $verdict->status);
        self::assertTrue($verdict->isApproved());
        self::assertSame([], $verdict->requiredWork);
    }

    #[Test]
    public function rejectVerdictCanCarryFollowUpWork(): void
    {
        $followUp = new WorkItem(Activity::Editing, 'Fix review finding', id: 'work_fix');
        $verdict = ReviewVerdict::reject('unsafe patch', [$followUp]);

        self::assertSame(ReviewStatus::Rejected, $verdict->status);
        self::assertTrue($verdict->isRejected());
        self::assertSame([$followUp], $verdict->requiredWork);
    }

    #[Test]
    public function revisionVerdictRequiresFollowUpWork(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires follow-up work');

        ReviewVerdict::revise('needs tests', []);
    }
}
