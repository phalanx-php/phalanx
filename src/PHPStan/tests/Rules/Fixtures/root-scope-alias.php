<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\ExecutionScope;
use Phalanx\Scope;
use Phalanx\Scope\ExecutionScope as CurrentExecutionScope;
use Phalanx\Scope\Scope as CurrentScope;

final class RootScopeAliasFixture
{
    public function stale(ExecutionScope $executionScope, Scope $scope): void
    {
    }

    public function current(CurrentExecutionScope $executionScope, CurrentScope $scope): void
    {
    }
}
