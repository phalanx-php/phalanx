<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

final class ShowRouteId implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        return $scope->attribute('route.id');
    }
}
