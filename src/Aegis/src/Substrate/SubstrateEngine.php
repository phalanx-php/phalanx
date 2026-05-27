<?php

declare(strict_types=1);

namespace Phalanx\Substrate;

interface SubstrateEngine
{
    public function coroutine(): CoroutineDriver;

    public function channels(): ChannelFactory;

    public function timers(): TimerDriver;

    public function hooks(): RuntimeHookDriver;

    public function signals(): SignalDriver;

    public function waitGroup(): WaitGroupHandle;

    public function name(): string;
}
