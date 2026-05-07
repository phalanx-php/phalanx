<?php

declare(strict_types=1);

namespace Phalanx\Styx\Terminal;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Styx\Emitter;

final class Drain
{
    public function __construct(
        private readonly Emitter $source,
    ) {
    }

    public function __invoke(ExecutionScope $scope): void
    {
        foreach (($this->source)($scope) as $_) {
            $scope->throwIfCancelled();
        }
    }
}
