<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Diagnostics;

use Phalanx\Diagnostics\DeadlockFrame;
use Phalanx\Diagnostics\DeadlockReport;
use PHPUnit\Framework\TestCase;

/**
 * Live-coroutine collection is exercised in integration tests where the
 * OpenSwoole runtime is active. Unit coverage exercises the report shape
 * via the fromFrames() factory used by the formatter.
 */
final class DeadlockReportTest extends TestCase
{
    public function testFormatRendersHeaderAndPerCoroutineFrames(): void
    {
        $report = DeadlockReport::fromFrames(2, [
            new DeadlockFrame(7, "#0 acquire() at /pool.php:42\n#1 query() at /work.php:15"),
            new DeadlockFrame(8, "#0 wait() at /channel.php:99"),
        ]);

        $rendered = $report->format();

        self::assertStringContainsString('[DEADLOCK REPORT]: 2 coroutines parked', $rendered);
        self::assertStringContainsString('[Coroutine-7]', $rendered);
        self::assertStringContainsString('acquire() at /pool.php:42', $rendered);
        self::assertStringContainsString('[Coroutine-8]', $rendered);
        self::assertStringContainsString('wait() at /channel.php:99', $rendered);
    }

    public function testEmptyReportRendersHeaderOnly(): void
    {
        $report = DeadlockReport::fromFrames(0, []);

        $rendered = $report->format();

        self::assertStringContainsString('[DEADLOCK REPORT]: 0 coroutines parked', $rendered);
        self::assertStringNotContainsString('[Coroutine-', $rendered);
    }

    public function testCollectedAtIsRecorded(): void
    {
        $before = microtime(true);
        $report = DeadlockReport::fromFrames(1, [new DeadlockFrame(1, '')]);
        $after = microtime(true);

        self::assertGreaterThanOrEqual($before, $report->collectedAt);
        self::assertLessThanOrEqual($after, $report->collectedAt);
    }

    public function testFrameDataExposed(): void
    {
        $report = DeadlockReport::fromFrames(1, [new DeadlockFrame(42, 'trace')]);

        self::assertSame(1, $report->coroutineCount);
        self::assertCount(1, $report->frames);
        self::assertSame(42, $report->frames[0]->cid);
        self::assertSame('trace', $report->frames[0]->backtrace);
    }
}
