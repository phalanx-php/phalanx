<?php

declare(strict_types=1);

namespace Phalanx\Worker;

use Phalanx\Actor\ActorMessage;
use Phalanx\Coordination\AtomicRef;
use Phalanx\Coordination\CoordinatedWrite;

interface CoordinatedWorkerScope extends WorkerScope
{
    public function swap(CoordinatedWrite $write): mixed;

    public function alter(CoordinatedWrite $write): mixed;

    public function send(ActorMessage $message): void;

    public function read(AtomicRef $ref): mixed;
}
