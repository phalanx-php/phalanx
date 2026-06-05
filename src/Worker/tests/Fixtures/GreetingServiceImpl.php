<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Fixtures;

final class GreetingServiceImpl implements GreetingService
{
    public function greet(string $name): string
    {
        return "hello {$name}";
    }
}
