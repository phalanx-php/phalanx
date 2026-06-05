<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Scope\TaskScope;

interface Mountable
{
    public function onMount(TaskScope $scope): void;
    public function onUnmount(): void;
}
