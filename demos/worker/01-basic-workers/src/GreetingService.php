<?php

declare(strict_types=1);

namespace Phalanx\Demos\Worker\BasicWorkers;

interface GreetingService
{
    public function greet(string $name): string;
}
