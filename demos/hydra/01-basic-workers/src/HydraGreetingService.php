<?php

declare(strict_types=1);

namespace Phalanx\Demos\Hydra\BasicWorkers;

interface HydraGreetingService
{
    public function greet(string $name): string;
}
