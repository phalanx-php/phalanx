<?php

declare(strict_types=1);

namespace Phalanx\Invocation;

use Phalanx\Mark\Mark;
use Phalanx\Scope\Scope;

abstract class Ctx implements InvocationCtx
{
    /** Sealed projections: scope state is readable, the scope reference is not (B7). */
    final public bool $cancelled {
        get => $this->scope->isCancelled();
    }

    final public Mark $remaining {
        get => $this->scope->remaining();
    }

    public function __construct(
        private(set) InvocationId $id,
        private(set) Attempt $attempt,
        private readonly Scope $scope,
    ) {
    }
}
