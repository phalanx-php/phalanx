<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Phalanx\Scope\ExecutionScope;

final readonly class MatchResult
{
    public function __construct(
        public ExecutionScope $scope,
        public Handler $handler,
    ) {
    }
}
