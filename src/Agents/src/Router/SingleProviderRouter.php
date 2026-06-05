<?php

declare(strict_types=1);

namespace Phalanx\Agents\Router;

use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Provider;
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
