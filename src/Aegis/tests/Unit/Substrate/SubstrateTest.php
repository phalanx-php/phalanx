<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Tests\Unit\Substrate;

use Phalanx\Substrate\ChannelFactory;
use Phalanx\Substrate\CoroutineDriver;
use Phalanx\Substrate\RuntimeHookDriver;
use Phalanx\Substrate\SignalDriver;
use Phalanx\Substrate\Substrate;
use Phalanx\Substrate\SubstrateEngine;
use Phalanx\Substrate\TimerDriver;
use Phalanx\Substrate\WaitGroupHandle;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SubstrateTest extends TestCase
{
    protected function tearDown(): void
    {
        Substrate::reset();
    }

    public function testNotBootedByDefault(): void
    {
        $this->assertFalse(Substrate::isBooted());
    }

    public function testBootSetsEngine(): void
    {
        $engine = $this->createMock(SubstrateEngine::class);
        $engine->method('name')->willReturn('test');

        Substrate::boot($engine);

        $this->assertTrue(Substrate::isBooted());
        $this->assertSame('test', Substrate::name());
    }

    public function testCoroutineAccessDelegatesToEngine(): void
    {
        $driver = $this->createMock(CoroutineDriver::class);
        $engine = $this->createMock(SubstrateEngine::class);
        $engine->method('coroutine')->willReturn($driver);

        Substrate::boot($engine);

        $this->assertSame($driver, Substrate::coroutine());
    }

    public function testChannelsAccessDelegatesToEngine(): void
    {
        $factory = $this->createMock(ChannelFactory::class);
        $engine = $this->createMock(SubstrateEngine::class);
        $engine->method('channels')->willReturn($factory);

        Substrate::boot($engine);

        $this->assertSame($factory, Substrate::channels());
    }

    public function testTimersAccessDelegatesToEngine(): void
    {
        $timers = $this->createMock(TimerDriver::class);
        $engine = $this->createMock(SubstrateEngine::class);
        $engine->method('timers')->willReturn($timers);

        Substrate::boot($engine);

        $this->assertSame($timers, Substrate::timers());
    }

    public function testHooksAccessDelegatesToEngine(): void
    {
        $hooks = $this->createMock(RuntimeHookDriver::class);
        $engine = $this->createMock(SubstrateEngine::class);
        $engine->method('hooks')->willReturn($hooks);

        Substrate::boot($engine);

        $this->assertSame($hooks, Substrate::hooks());
    }

    public function testSignalsAccessDelegatesToEngine(): void
    {
        $signals = $this->createMock(SignalDriver::class);
        $engine = $this->createMock(SubstrateEngine::class);
        $engine->method('signals')->willReturn($signals);

        Substrate::boot($engine);

        $this->assertSame($signals, Substrate::signals());
    }

    public function testWaitGroupAccessDelegatesToEngine(): void
    {
        $wg = $this->createMock(WaitGroupHandle::class);
        $engine = $this->createMock(SubstrateEngine::class);
        $engine->method('waitGroup')->willReturn($wg);

        Substrate::boot($engine);

        $this->assertSame($wg, Substrate::waitGroup());
    }

    public function testAccessBeforeBootThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Substrate not booted');

        Substrate::coroutine();
    }

    public function testResetClearsEngine(): void
    {
        $engine = $this->createMock(SubstrateEngine::class);
        Substrate::boot($engine);

        $this->assertTrue(Substrate::isBooted());

        Substrate::reset();

        $this->assertFalse(Substrate::isBooted());
    }
}
