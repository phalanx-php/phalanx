<?php

declare(strict_types=1);

namespace Phalanx;

use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

interface TaskScope extends Scope, Suspendable, Cancellable, Disposable
{
    public function execute(Scopeable|Executable $task): mixed;

    public function executeFresh(Scopeable|Executable $task): mixed;
}
