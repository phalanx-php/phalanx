<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

final class StuckHandler implements Executable
{
    public function __invoke(ExecutionScope $scope): string
    {
        $scope->delay(10.0);

        return 'completed';
    }
}
