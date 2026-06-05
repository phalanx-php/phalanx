<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Fixtures;

interface WorkerGreetingService
{
    public function greet(string $name): string;
}
