<?php

declare(strict_types=1);

namespace Convoy\Handler;

use Convoy\ExecutionScope;

final readonly class MatchResult
{
    public function __construct(
        public Handler $handler,
        public ExecutionScope $scope,
    ) {
    }
}
