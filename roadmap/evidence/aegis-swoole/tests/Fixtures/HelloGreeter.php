<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

class HelloGreeter implements Greeter
{
    public function greet(string $name): string
    {
        return "hello {$name}";
    }
}
