<?php

declare(strict_types=1);

namespace Phalanx\Demos\Hydra\BasicWorkers;

class HydraGreetingServiceImpl implements HydraGreetingService
{
    public function greet(string $name): string
    {
        return "hello {$name}";
    }
}
