<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Adapters\Agent;

use Phalanx\Agent\Activity\Config;
use Phalanx\Agent\Activity\Result;
use Phalanx\Agent\Agent as AgentFacade;
use Phalanx\AiProviders\Agent as AiAgent;
use Phalanx\AiProviders\Conversation\Log;
use Phalanx\Scope\TaskScope;

final class StaticAgentRunner implements AgentRunner
{
    public function __invoke(TaskScope $scope, AiAgent $agent, Config $config, ?Log $log = null): Result
    {
        return AgentFacade::run($scope, $agent, $config, $log);
    }
}
