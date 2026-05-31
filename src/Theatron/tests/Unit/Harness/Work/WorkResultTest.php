<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Harness\Work;

use Phalanx\Theatron\Harness\Message\Envelope;
use Phalanx\Theatron\Harness\Work\WorkResult;
use Phalanx\Theatron\Harness\Work\WorkResultStatus;
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
            payload: ['files' => ['src/Theatron']],
            summary: 'found files',
            envelopes: [$envelope],
        );

        self::assertSame(WorkResultStatus::Done, $result->status);
        self::assertSame('work_1', $result->itemId);
        self::assertSame(['files' => ['src/Theatron']], $result->payload);
        self::assertSame('found files', $result->summary);
        self::assertNull($result->error);
        self::assertTrue($result->isDone());
        self::assertFalse($result->isBlocked());
        self::assertSame([$envelope], $result->envelopes);
        self::assertSame('found files', $result->toCanonical()['summary']);
        self::assertSame(['files' => ['src/Theatron']], $result->toCanonical()['payload']);
    }

    #[Test]
    public function blockedResultIsResumableReasonNotFailure(): void
    {
        $result = WorkResult::blocked('work_api', 'missing token');

        self::assertSame(WorkResultStatus::Blocked, $result->status);
        self::assertSame('missing token', $result->summary);
        self::assertNull($result->error);
        self::assertTrue($result->isBlocked());
        self::assertSame([
            'item_id' => 'work_api',
            'status' => WorkResultStatus::Blocked,
            'payload' => null,
            'summary' => 'missing token',
            'error' => null,
            'envelopes' => [],
        ], $result->toCanonical());
    }

    #[Test]
    public function blockedResultRejectsEmptyReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reason cannot be empty');

        WorkResult::blocked('work_api', '');
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
        self::assertSame([
            'class' => \RuntimeException::class,
            'message' => 'provider unavailable',
        ], $result->toCanonical()['error']);
    }
}
