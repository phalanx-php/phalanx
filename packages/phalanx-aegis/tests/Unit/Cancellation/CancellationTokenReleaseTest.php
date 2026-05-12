<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Cancellation;

use Phalanx\Cancellation\CancellationToken;
use PHPUnit\Framework\TestCase;

final class CancellationTokenReleaseTest extends TestCase
{
    public function testReleaseDetachesCompositeFromParentCancellation(): void
    {
        $parent = CancellationToken::create();
        $composite = CancellationToken::composite($parent);

        $compositeFired = false;
        $composite->onCancel(static function () use (&$compositeFired): void {
            $compositeFired = true;
        });

        $composite->release();
        $parent->cancel();

        self::assertFalse($compositeFired);
        self::assertFalse($composite->isCancelled);
    }

    public function testParentListenerCountBoundedByActiveChildren(): void
    {
        $parent = CancellationToken::create();
        $composites = [];

        for ($i = 0; $i < 100; $i++) {
            $composites[] = CancellationToken::composite($parent);
        }

        for ($i = 0; $i < 99; $i++) {
            $composites[$i]->release();
        }

        $fired = false;
        $composites[99]->onCancel(static function () use (&$fired): void {
            $fired = true;
        });

        $parent->cancel();

        self::assertTrue($fired);
        self::assertTrue($composites[99]->isCancelled);
    }

    public function testReleaseAfterCancelIsIdempotent(): void
    {
        $parent = CancellationToken::create();
        $composite = CancellationToken::composite($parent);

        $cancelCount = 0;
        $composite->onCancel(static function () use (&$cancelCount): void {
            $cancelCount++;
        });

        $parent->cancel();
        self::assertSame(1, $cancelCount);

        $composite->release();
        self::assertSame(1, $cancelCount);
    }

    public function testReleaseClearsCompositeListeners(): void
    {
        $token = CancellationToken::create();

        $fired = false;
        $token->onCancel(static function () use (&$fired): void {
            $fired = true;
        });

        $token->release();
        $token->cancel();

        self::assertFalse($fired);
    }

    public function testReleaseOnNonCompositeIsHarmless(): void
    {
        $token = CancellationToken::create();
        $token->release();

        self::assertFalse($token->isCancelled);
    }

    public function testMultiSourceCompositeReleaseCleansAllParents(): void
    {
        $parentA = CancellationToken::create();
        $parentB = CancellationToken::create();
        $composite = CancellationToken::composite($parentA, $parentB);

        $compositeFired = false;
        $composite->onCancel(static function () use (&$compositeFired): void {
            $compositeFired = true;
        });

        $composite->release();

        $parentA->cancel();
        self::assertFalse($compositeFired);
        self::assertFalse($composite->isCancelled);

        $parentB->cancel();
        self::assertFalse($compositeFired);
        self::assertFalse($composite->isCancelled);
    }

    public function testUnreleasedCompositeFiresOnParentCancel(): void
    {
        $parent = CancellationToken::create();
        $composite = CancellationToken::composite($parent);

        $fired = false;
        $composite->onCancel(static function () use (&$fired): void {
            $fired = true;
        });

        $parent->cancel();

        self::assertTrue($fired);
        self::assertTrue($composite->isCancelled);
    }
}
