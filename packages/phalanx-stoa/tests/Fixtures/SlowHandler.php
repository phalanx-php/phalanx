<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

final class SlowHandler implements Executable
{
    public function __invoke(ExecutionScope $scope): string
    {
        $scope->delay(0.3);

        return 'completed';
    }
}
