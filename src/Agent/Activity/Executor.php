<?php

declare(strict_types=1);

namespace Phalanx\Agent\Activity;

use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Conversation\Log;
use Phalanx\Scope\TaskScope;

interface Executor
{
    public function __invoke(TaskScope $scope, Agent $agent, Config $config, ?Log $log = null): Result;
}
