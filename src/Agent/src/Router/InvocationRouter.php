<?php

declare(strict_types=1);

namespace Phalanx\Agent\Router;

use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Provider;
use Phalanx\Scope\TaskScope;

interface InvocationRouter
{
    public function route(TaskScope $scope, Agent $agent, Invocation $invocation): Provider;
}
