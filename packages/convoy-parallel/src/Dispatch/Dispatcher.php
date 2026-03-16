<?php

declare(strict_types=1);

namespace Convoy\Parallel\Dispatch;

use Convoy\Parallel\Protocol\TaskRequest;
use React\Promise\PromiseInterface;

interface Dispatcher
{
    public function dispatch(TaskRequest $task): PromiseInterface;
}
