<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Scope\TaskScope;

interface Mountable
{
    public function onMount(TaskScope $scope): void;
    public function onUnmount(): void;
}
