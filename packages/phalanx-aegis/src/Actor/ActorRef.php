<?php

declare(strict_types=1);

namespace Phalanx\Actor;

interface ActorRef
{
    public function tell(ActorMessage $message): void;

    public function ask(ActorMessage $message, ?float $timeout = null): mixed;

    public function join(): JoinHandle;

    public function stop(bool $graceful = true): StopResult;
}
