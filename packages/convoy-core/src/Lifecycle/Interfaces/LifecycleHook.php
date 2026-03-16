<?php

declare(strict_types=1);

namespace Convoy\Lifecycle\Interfaces;

use Convoy\Lifecycle\LifecyclePhase;

interface LifecycleHook
{
    public function phase(): LifecyclePhase;

    public function execute(object $service): void;
}
