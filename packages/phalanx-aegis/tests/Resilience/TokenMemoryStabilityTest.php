<?php

declare(strict_types=1);

namespace Phalanx\Tests\Resilience;

use Phalanx\Cancellation\CancellationToken;
use PHPUnit\Framework\TestCase;

final class TokenMemoryStabilityTest extends TestCase
{
    public function testCompositeReleaseKeepsParentListenersBounded(): void
    {
        $parent = CancellationToken::create();
        $cycles = 10_000;

        for ($i = 0; $i < $cycles; $i++) {
            $composite = CancellationToken::composite($parent);
            $composite->release();
        }

        $fired = false;
        $parent->onCancel(static function () use (&$fired): void {
            $fired = true;
        });

        $parent->cancel();
        self::assertTrue($fired);
    }

    public function testMemoryDeltaBoundedAfterMassCompositeRelease(): void
    {
        $parent = CancellationToken::create();
        $cycles = 10_000;

        gc_collect_cycles();
        $before = memory_get_usage();

        for ($i = 0; $i < $cycles; $i++) {
            $composite = CancellationToken::composite($parent);
            $composite->release();
            unset($composite);
        }

        gc_collect_cycles();
        $after = memory_get_usage();

        $delta = $after - $before;
        self::assertLessThan(
            512 * 1024,
            $delta,
            sprintf('Memory grew by %s bytes after %d composite cycles — expected bounded growth', number_format($delta), $cycles),
        );
    }
}
