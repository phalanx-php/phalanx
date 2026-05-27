<?php

declare(strict_types=1);

namespace Phalanx\Substrate\Swoole;

use Phalanx\Substrate\ChannelFactory;
use Phalanx\Substrate\ChannelWaitGroup;
use Phalanx\Substrate\CoroutineDriver;
use Phalanx\Substrate\RuntimeHookDriver;
use Phalanx\Substrate\SignalDriver;
use Phalanx\Substrate\SubstrateEngine;
use Phalanx\Substrate\TimerDriver;
use Phalanx\Substrate\WaitGroupHandle;

final class SwooleEngine implements SubstrateEngine
{
    private ?SwooleCoroutineDriver $coroutine = null;
    private ?SwooleChannelFactory $channels = null;
    private ?SwooleTimerDriver $timers = null;
    private ?SwooleRuntimeHookDriver $hooks = null;
    private ?SwooleSignalDriver $signals = null;

    public function coroutine(): CoroutineDriver
    {
        return $this->coroutine ??= new SwooleCoroutineDriver();
    }

    public function channels(): ChannelFactory
    {
        return $this->channels ??= new SwooleChannelFactory();
    }

    public function timers(): TimerDriver
    {
        return $this->timers ??= new SwooleTimerDriver();
    }

    public function hooks(): RuntimeHookDriver
    {
        return $this->hooks ??= new SwooleRuntimeHookDriver();
    }

    public function signals(): SignalDriver
    {
        return $this->signals ??= new SwooleSignalDriver();
    }

    public function waitGroup(): WaitGroupHandle
    {
        if (class_exists(\Swoole\Coroutine\WaitGroup::class, false)) {
            return new SwooleWaitGroupHandle();
        }

        return new ChannelWaitGroup();
    }

    public function name(): string
    {
        return 'swoole';
    }
}
