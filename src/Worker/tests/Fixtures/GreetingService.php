<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Fixtures;

interface GreetingService
{
    public function greet(string $name): string;
}
