<?php

declare(strict_types=1);

namespace Phalanx\Demos\Worker\BasicWorkers;

class WorkerGreetingServiceImpl implements WorkerGreetingService
{
    public function greet(string $name): string
    {
        return "hello {$name}";
    }
}
