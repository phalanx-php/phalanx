<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Cancellation;

use Phalanx\Cancellation\CancellationToken;
use PHPUnit\Framework\TestCase;

final class CancellationTokenReleaseTest extends TestCase
{
    public function testReleaseDetachesCompositeFromParentCancellation(): void
    {
        $parent = CancellationToken::create();
        $composite = CancellationToken::composite($parent);

        $compositeProbe = new CancellationProbe();
        $composite->onCancel(static function () use ($compositeProbe): void {
            $compositeProbe->record();
        });

        $composite->release();
        $parent->cancel();

        self::assertSame(0, $compositeProbe->count);
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

        $probe = new CancellationProbe();
        $composites[99]->onCancel(static function () use ($probe): void {
            $probe->record();
        });

        $parent->cancel();

        self::assertSame(1, $probe->count);
        self::assertTrue($composites[99]->isCancelled);
    }

    public function testReleaseAfterCancelIsIdempotent(): void
    {
        $parent = CancellationToken::create();
        $composite = CancellationToken::composite($parent);

        $probe = new CancellationProbe();
        $composite->onCancel(static function () use ($probe): void {
            $probe->record();
        });

        $parent->cancel();
        self::assertSame(1, $probe->count);

        $composite->release();
        self::assertSame(1, $probe->count);
    }

    public function testReleaseClearsListeners(): void
    {
        $token = CancellationToken::create();

        $probe = new CancellationProbe();
        $token->onCancel(static function () use ($probe): void {
            $probe->record();
        });

        $token->release();
        $token->cancel();

        self::assertSame(0, $probe->count);
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

        $compositeProbe = new CancellationProbe();
        $composite->onCancel(static function () use ($compositeProbe): void {
            $compositeProbe->record();
        });

        $composite->release();

        $parentA->cancel();
        self::assertSame(0, $compositeProbe->count);
        self::assertFalse($composite->isCancelled);

        $parentB->cancel();
        self::assertSame(0, $compositeProbe->count);
        self::assertFalse($composite->isCancelled);
    }

    public function testUnreleasedCompositeFiresOnParentCancel(): void
    {
        $parent = CancellationToken::create();
        $composite = CancellationToken::composite($parent);

        $probe = new CancellationProbe();
        $composite->onCancel(static function () use ($probe): void {
            $probe->record();
        });

        $parent->cancel();

        self::assertSame(1, $probe->count);
        self::assertTrue($composite->isCancelled);
    }
}

final class CancellationProbe
{
    public int $count = 0;

    public function record(): void
    {
        $this->count++;
    }
}
