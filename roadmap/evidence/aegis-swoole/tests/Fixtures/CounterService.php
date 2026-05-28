<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

class CounterService
{
    public int $count = 0;

    public function bump(): int
    {
        return ++$this->count;
    }
}
