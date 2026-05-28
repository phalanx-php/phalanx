<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

class LifecycleService
{
    public int $initCount = 0;

    public int $startupCount = 0;

    public int $shutdownCount = 0;
}
