<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Worker\WorkerScope;
use Phalanx\Worker\WorkerTask;

final class ExecutionScopeWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __invoke(ExecutionScope $scope): int
    {
        return 1;
    }
}

final class UnknownScopeWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __invoke(NonWorkerPayload $scope): int
    {
        return 1;
    }
}

final class ValidScopeWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __invoke(WorkerScope|Scope $scope): int
    {
        return 1;
    }
}

interface AliasWorkerTask extends WorkerTask
{
}

final class AliasExecutionScopeWorkerTask implements AliasWorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __invoke(ExecutionScope $scope): int
    {
        return 1;
    }
}

final class MissingScopeWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __invoke(): int
    {
        return 1;
    }
}
