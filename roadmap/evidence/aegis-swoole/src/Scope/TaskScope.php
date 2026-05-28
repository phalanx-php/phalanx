<?php

declare(strict_types=1);

namespace AegisSwoole\Scope;

use AegisSwoole\Task\Executable;
use AegisSwoole\Task\Scopeable;
use Closure;

interface TaskScope extends Scope, Suspendable, Cancellable, Disposable
{
    public function execute(Scopeable|Executable|Closure $task): mixed;

    public function executeFresh(Scopeable|Executable|Closure $task): mixed;
}
