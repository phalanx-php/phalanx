<?php

declare(strict_types=1);

namespace AegisSwoole\Worker;

use AegisSwoole\Cancellation\CancellationToken;
use AegisSwoole\Task\Executable;
use AegisSwoole\Task\Scopeable;

interface WorkerDispatch
{
    public function dispatch(Scopeable|Executable $task, CancellationToken $token): mixed;

    public function shutdown(): void;
}
