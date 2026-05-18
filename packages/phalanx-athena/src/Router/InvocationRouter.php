<?php

declare(strict_types=1);

namespace Phalanx\Athena\Router;

use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider;
use Phalanx\Scope\TaskScope;

interface InvocationRouter
{
    public function route(TaskScope $scope, Agent $agent, Invocation $invocation): Provider;
}
