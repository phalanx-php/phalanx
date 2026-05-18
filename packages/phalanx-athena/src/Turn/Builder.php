<?php

declare(strict_types=1);

namespace Phalanx\Athena\Turn;

use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Invocation;
use Phalanx\Scope\TaskScope;

interface Builder
{
    public function build(TaskScope $scope, Agent $agent, Log $log, Config $config): Invocation;
}
