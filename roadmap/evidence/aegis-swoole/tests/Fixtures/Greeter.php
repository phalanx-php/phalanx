<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

interface Greeter
{
    public function greet(string $name): string;
}
