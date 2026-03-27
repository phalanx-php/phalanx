<?php

declare(strict_types=1);

namespace Phalanx\Lifecycle\Interfaces;

use Phalanx\Lifecycle\LifecyclePhase;

interface LifecycleHook
{
    public function phase(): LifecyclePhase;

    public function execute(object $service): void;
}
