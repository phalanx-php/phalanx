<?php

declare(strict_types=1);

namespace Phalanx\Athena\Activity;

use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Scope\TaskScope;

interface Executor
{
    public function __invoke(TaskScope $scope, Agent $agent, Config $config, ?Log $log = null): Result;
}
