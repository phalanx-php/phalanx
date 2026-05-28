<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

use AegisSwoole\Scope\Scope;
use AegisSwoole\Task\Scopeable;

class PlainScopeable implements Scopeable
{
    public int $invocations = 0;

    public function __invoke(Scope $scope): string
    {
        $this->invocations++;
        return 'plain';
    }
}
