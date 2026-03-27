<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Phalanx\ExecutionScope;

interface HandlerMatcher
{
    /** @param array<string, Handler> $handlers */
    public function match(ExecutionScope $scope, array $handlers): ?MatchResult;
}
