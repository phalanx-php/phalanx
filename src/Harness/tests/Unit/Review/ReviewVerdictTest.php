<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Review;

use Phalanx\Harness\Review\ReviewStatus;
use Phalanx\Harness\Review\ReviewVerdict;
use Phalanx\Harness\Work\Activity;
use Phalanx\Harness\Work\WorkItem;
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
