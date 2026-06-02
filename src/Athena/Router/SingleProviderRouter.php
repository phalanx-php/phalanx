<?php

declare(strict_types=1);

namespace Phalanx\Athena\Router;

use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider;
use Phalanx\Scope\TaskScope;

final class SingleProviderRouter implements InvocationRouter
{
    public function __construct(
        private(set) Provider $provider,
    ) {
    }

    public function route(TaskScope $scope, Agent $agent, Invocation $invocation): Provider
    {
        return $this->provider;
    }
}
