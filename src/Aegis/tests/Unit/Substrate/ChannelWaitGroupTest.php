<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Tests\Unit\Substrate;

use Phalanx\Substrate\Swoole\SwooleChannelWaitGroup;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

#[RequiresPhpExtension('openswoole')]
final class ChannelWaitGroupTest extends TestCase
{
    public function testWaitReturnsImmediatelyWhenCountIsZero(): void
    {
        Coroutine::run(static function (): void {
            $wg = new SwooleChannelWaitGroup();

            self::assertTrue($wg->wait());
            self::assertSame(0, $wg->count());
        });
    }

    public function testAddIncrements(): void
    {
        Coroutine::run(static function (): void {
            $wg = new SwooleChannelWaitGroup();
            $wg->add();

            self::assertSame(1, $wg->count());

            $wg->add(3);

            self::assertSame(4, $wg->count());
        });
    }

    public function testDoneDecrementsCount(): void
    {
        Coroutine::run(static function (): void {
            $wg = new SwooleChannelWaitGroup();
            $wg->add(2);
            $wg->done();

            self::assertSame(1, $wg->count());
        });
    }

    public function testWaitSucceedsAfterAllDoneCalls(): void
    {
        Coroutine::run(static function (): void {
            $wg = new SwooleChannelWaitGroup();
            $wg->add(2);

            Coroutine::create(static function () use ($wg): void {
                $wg->done();
                $wg->done();
            });

            self::assertTrue($wg->wait());
            self::assertSame(0, $wg->count());
        });
    }

    public function testExcessDoneIsNoOp(): void
    {
        Coroutine::run(static function (): void {
            $wg = new SwooleChannelWaitGroup();
            $wg->add(1);
            $wg->done();

            $wg->done();
            $wg->done();

            self::assertSame(0, $wg->count());
        });
    }

    public function testDoneWithoutAddIsNoOp(): void
    {
        Coroutine::run(static function (): void {
            $wg = new SwooleChannelWaitGroup();

            $wg->done();

            self::assertSame(0, $wg->count());
        });
    }

    public function testWaitWithTimeoutReturnsFalse(): void
    {
        Coroutine::run(static function (): void {
            $wg = new SwooleChannelWaitGroup();
            $wg->add(1);

            self::assertFalse($wg->wait(0.01));
        });
    }
}
