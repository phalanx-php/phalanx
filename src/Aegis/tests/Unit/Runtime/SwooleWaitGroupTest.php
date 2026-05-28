<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Tests\Unit\Runtime;

use Phalanx\Runtime\Swoole\SwooleRuntime;
use Phalanx\Runtime\Swoole\SwooleWaitGroup;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SwooleWaitGroupTest extends TestCase
{
    #[Test]
    public function waitReturnsImmediatelyWhenCountIsZero(): void
    {
        SwooleRuntime::run(static function (): void {
            $wg = new SwooleWaitGroup();

            self::assertTrue($wg->wait());
            self::assertSame(0, $wg->count());
        });
    }

    #[Test]
    public function addIncrements(): void
    {
        SwooleRuntime::run(static function (): void {
            $wg = new SwooleWaitGroup();
            $wg->add();

            self::assertSame(1, $wg->count());

            $wg->add(3);

            self::assertSame(4, $wg->count());
        });
    }

    #[Test]
    public function doneDecrementsCount(): void
    {
        SwooleRuntime::run(static function (): void {
            $wg = new SwooleWaitGroup();
            $wg->add(2);
            $wg->done();

            self::assertSame(1, $wg->count());
        });
    }

    #[Test]
    public function waitSucceedsAfterAllDoneCalls(): void
    {
        SwooleRuntime::run(static function (): void {
            $wg = new SwooleWaitGroup();
            $wg->add(2);

            SwooleRuntime::create(static function () use ($wg): void {
                $wg->done();
                $wg->done();
            });

            self::assertTrue($wg->wait());
            self::assertSame(0, $wg->count());
        });
    }

    #[Test]
    public function excessDoneIsNoOp(): void
    {
        SwooleRuntime::run(static function (): void {
            $wg = new SwooleWaitGroup();
            $wg->add(1);
            $wg->done();

            $wg->done();
            $wg->done();

            self::assertSame(0, $wg->count());
        });
    }

    #[Test]
    public function doneWithoutAddIsNoOp(): void
    {
        SwooleRuntime::run(static function (): void {
            $wg = new SwooleWaitGroup();

            $wg->done();

            self::assertSame(0, $wg->count());
        });
    }

    #[Test]
    public function waitWithTimeoutReturnsFalse(): void
    {
        SwooleRuntime::run(static function (): void {
            $wg = new SwooleWaitGroup();
            $wg->add(1);

            self::assertFalse($wg->wait(0.01));
        });
    }
}
