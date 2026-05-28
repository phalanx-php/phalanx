<?php

declare(strict_types=1);

namespace Phalanx\Engine;

interface EngineDriver
{
    public function coroutine(): CoroutineDriver;

    public function channels(): ChannelFactory;

    public function timers(): TimerDriver;

    public function hooks(): RuntimeHookDriver;

    public function signals(): SignalDriver;

    public function createWaitGroup(): WaitGroupHandle;

    public function name(): string;
}
