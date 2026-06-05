<?php

declare(strict_types=1);

namespace Phalanx\Agents\Turn;

use Phalanx\AiProviders\Runtime;
use Phalanx\Scope\TaskScope;

interface RuntimeFactory
{
    public function __invoke(TaskScope $scope): Runtime;
}
