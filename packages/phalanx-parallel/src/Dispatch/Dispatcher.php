<?php

declare(strict_types=1);

namespace Phalanx\Parallel\Dispatch;

use Phalanx\Parallel\Protocol\TaskRequest;
use React\Promise\PromiseInterface;

interface Dispatcher
{
    public function dispatch(TaskRequest $task): PromiseInterface;
}
