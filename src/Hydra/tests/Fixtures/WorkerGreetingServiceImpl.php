<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Tests\Fixtures;

final class WorkerGreetingServiceImpl implements WorkerGreetingService
{
    public function greet(string $name): string
    {
        return "hello {$name}";
    }
}
