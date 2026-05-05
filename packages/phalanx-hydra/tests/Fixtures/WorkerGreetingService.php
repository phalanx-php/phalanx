<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Tests\Fixtures;

interface WorkerGreetingService
{
    public function greet(string $name): string;
}
