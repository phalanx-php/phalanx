<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Dispatch;

use Phalanx\Hydra\Protocol\TaskRequest;
use React\Promise\PromiseInterface;

interface Dispatcher
{
    /** @return PromiseInterface<mixed> */
    public function dispatch(TaskRequest $task): PromiseInterface;
}
