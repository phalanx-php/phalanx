<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Executable;

final class ShowRouteId implements Executable
{
    public function __invoke(RequestScope $scope): mixed
    {
        return $scope->params->get('id');
    }
}
