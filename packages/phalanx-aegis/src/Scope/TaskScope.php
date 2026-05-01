<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Closure;

interface TaskScope extends Scope, Suspendable, Cancellable, Disposable
{
    public function execute(Scopeable|Executable|Closure $task): mixed;

    public function executeFresh(Scopeable|Executable|Closure $task): mixed;
}
