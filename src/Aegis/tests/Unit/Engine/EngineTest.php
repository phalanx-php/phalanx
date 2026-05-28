<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Tests\Unit\Engine;

use Phalanx\Engine\ChannelFactory;
use Phalanx\Engine\CoroutineDriver;
use Phalanx\Engine\Engine;
use Phalanx\Engine\EngineDriver;
use Phalanx\Engine\RuntimeHookDriver;
use Phalanx\Engine\SignalDriver;
use Phalanx\Engine\TimerDriver;
use Phalanx\Engine\WaitGroupHandle;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EngineTest extends TestCase
{
    public function testNotBootedByDefault(): void
    {
        $this->assertFalse(Engine::isBooted());
    }

    public function testBootSetsEngine(): void
    {
        $engine = $this->createStub(EngineDriver::class);
        $engine->method('name')->willReturn('test');

        Engine::boot($engine);

        $this->assertTrue(Engine::isBooted());
        $this->assertSame('test', Engine::name());
    }

    public function testCoroutineAccessDelegatesToEngine(): void
    {
        $driver = $this->createStub(CoroutineDriver::class);
        $engine = $this->createStub(EngineDriver::class);
        $engine->method('coroutine')->willReturn($driver);

        Engine::boot($engine);

        $this->assertSame($driver, Engine::coroutine());
    }

    public function testChannelsAccessDelegatesToEngine(): void
    {
        $factory = $this->createStub(ChannelFactory::class);
        $engine = $this->createStub(EngineDriver::class);
        $engine->method('channels')->willReturn($factory);

        Engine::boot($engine);

        $this->assertSame($factory, Engine::channels());
    }

    public function testTimersAccessDelegatesToEngine(): void
    {
        $timers = $this->createStub(TimerDriver::class);
        $engine = $this->createStub(EngineDriver::class);
        $engine->method('timers')->willReturn($timers);

        Engine::boot($engine);

        $this->assertSame($timers, Engine::timers());
    }

    public function testHooksAccessDelegatesToEngine(): void
    {
        $hooks = $this->createStub(RuntimeHookDriver::class);
        $engine = $this->createStub(EngineDriver::class);
        $engine->method('hooks')->willReturn($hooks);

        Engine::boot($engine);

        $this->assertSame($hooks, Engine::hooks());
    }

    public function testSignalsAccessDelegatesToEngine(): void
    {
        $signals = $this->createStub(SignalDriver::class);
        $engine = $this->createStub(EngineDriver::class);
        $engine->method('signals')->willReturn($signals);

        Engine::boot($engine);

        $this->assertSame($signals, Engine::signals());
    }

    public function testCreateWaitGroupDelegatesToEngine(): void
    {
        $wg = $this->createStub(WaitGroupHandle::class);
        $engine = $this->createStub(EngineDriver::class);
        $engine->method('createWaitGroup')->willReturn($wg);

        Engine::boot($engine);

        $this->assertSame($wg, Engine::createWaitGroup());
    }

    public function testAccessBeforeBootThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Engine not booted');

        Engine::coroutine();
    }

    public function testResetClearsEngine(): void
    {
        $engine = $this->createStub(EngineDriver::class);
        Engine::boot($engine);

        $this->assertTrue(Engine::isBooted());

        Engine::reset();

        $this->assertFalse(Engine::isBooted());
    }

    public function testDoubleBootThrows(): void
    {
        $engine = $this->createStub(EngineDriver::class);
        Engine::boot($engine);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Engine already booted');

        Engine::boot($engine);
    }

    public function testAccessAfterResetThrows(): void
    {
        $engine = $this->createStub(EngineDriver::class);
        Engine::boot($engine);
        Engine::reset();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Engine not booted');

        Engine::coroutine();
    }

    protected function setUp(): void
    {
        Engine::reset();
    }

    protected function tearDown(): void
    {
        Engine::reset();
    }
}
