<?php

declare(strict_types=1);

namespace Convoy\Handler;

use Convoy\ExecutionScope;

interface HandlerMatcher
{
    /** @param array<string, Handler> $handlers */
    public function match(ExecutionScope $scope, array $handlers): ?MatchResult;
}
