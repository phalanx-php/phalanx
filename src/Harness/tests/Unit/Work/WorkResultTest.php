<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Work;

use Phalanx\Harness\Message\Envelope;
use Phalanx\Harness\Work\WorkResult;
use Phalanx\Harness\Work\WorkResultStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkResultTest extends TestCase
{
    #[Test]
    public function doneResultCarriesPayloadSummaryAndEnvelopes(): void
    {
        $envelope = Envelope::prompt('show output');
        $result = WorkResult::done(
            itemId: 'work_1',
            payload: ['files' => ['src/Harness']],
            summary: 'found files',
            envelopes: [$envelope],
        );

        self::assertSame(WorkResultStatus::Done, $result->status);
        self::assertTrue($result->isDone());
        self::assertFalse($result->isBlocked());
        self::assertSame([$envelope], $result->envelopes);
    }

    #[Test]
    public function blockedResultIsResumableReasonNotFailure(): void
    {
        $result = WorkResult::blocked('work_api', 'missing token');

        self::assertSame(WorkResultStatus::Blocked, $result->status);
        self::assertSame('missing token', $result->summary);
        self::assertNull($result->error);
        self::assertTrue($result->isBlocked());
    }

    #[Test]
    public function failedResultCarriesThrowableSummary(): void
    {
        $error = new \RuntimeException('provider unavailable');
        $result = WorkResult::failed('work_llm', $error);

        self::assertSame(WorkResultStatus::Failed, $result->status);
        self::assertSame($error, $result->error);
        self::assertSame('provider unavailable', $result->summary);
        self::assertTrue($result->isFailed());
    }
}
