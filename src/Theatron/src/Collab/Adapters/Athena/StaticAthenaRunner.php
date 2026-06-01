<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Adapters\Athena;

use Phalanx\Athena\Activity\Config;
use Phalanx\Athena\Activity\Result;
use Phalanx\Athena\Athena;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Scope\TaskScope;

final class StaticAthenaRunner implements AthenaRunner
{
    public function __invoke(TaskScope $scope, Agent $agent, Config $config, ?Log $log = null): Result
    {
        return Athena::run($scope, $agent, $config, $log);
    }
}
