<?php

declare(strict_types=1);

namespace Phalanx\Engine\Swoole;

use Phalanx\Engine\ChannelFactory;
use Phalanx\Engine\CoroutineDriver;
use Phalanx\Engine\EngineDriver;
use Phalanx\Engine\RuntimeHookDriver;
use Phalanx\Engine\SignalDriver;
use Phalanx\Engine\TimerDriver;
use Phalanx\Engine\WaitGroupHandle;

final class SwooleEngine implements EngineDriver
{
    private ?SwooleCoroutineDriver $coroutine = null;
    private ?SwooleChannelFactory $channels = null;
    private ?SwooleTimerDriver $timers = null;
    private ?SwooleRuntimeHookDriver $hooks = null;
    private ?SwooleSignalDriver $signals = null;
    private ?bool $hasSwooleWaitGroup = null;

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

    public function createWaitGroup(): WaitGroupHandle
    {
        $this->hasSwooleWaitGroup ??= class_exists(\Swoole\Coroutine\WaitGroup::class, false);

        if ($this->hasSwooleWaitGroup) {
            return new SwooleWaitGroupHandle();
        }

        return new SwooleChannelWaitGroup();
    }

    public function name(): string
    {
        return 'swoole';
    }
}
