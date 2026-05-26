<?php

declare(strict_types=1);

namespace Phalanx\Dory;

use Closure;
use Phalanx\Dory\Orchestration\AttemptBuilder;
use Phalanx\Scope\ExecutionScope;

interface ScriptScope extends ExecutionScope
{
    public string $scriptPath { get; }

    public string $scriptName { get; }

    public DoryConfig $config { get; }

    public function attempt(Closure $task): AttemptBuilder;

    public function dump(mixed ...$values): void;

    public function println(string $message = ''): void;
}
