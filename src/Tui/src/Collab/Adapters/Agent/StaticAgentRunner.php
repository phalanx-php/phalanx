<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Adapters\Agent;

use Phalanx\Agents\Activity\Config;
use Phalanx\Agents\Activity\Result;
use Phalanx\Agents\Agents;
use Phalanx\AiProviders\Agent as AiAgent;
use Phalanx\AiProviders\Conversation\Log;
use Phalanx\Scope\TaskScope;

final class StaticAgentRunner implements AgentRunner
{
    public function __invoke(TaskScope $scope, AiAgent $agent, Config $config, ?Log $log = null): Result
    {
        return Agents::run($scope, $agent, $config, $log);
    }
}
