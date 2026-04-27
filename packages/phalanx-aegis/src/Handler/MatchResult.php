<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Phalanx\ExecutionScope;

final readonly class MatchResult
{
    public function __construct(
        public Handler $handler,
        public ExecutionScope $scope,
    ) {
    }
}
