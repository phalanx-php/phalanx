<?php

declare(strict_types=1);

namespace Phalanx\Agents\Turn;

use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Conversation\Log;
use Phalanx\AiProviders\Invocation;
use Phalanx\Scope\TaskScope;

interface Builder
{
    public function build(TaskScope $scope, Agent $agent, Log $log, Config $config): Invocation;
}
