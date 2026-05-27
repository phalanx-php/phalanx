<?php

declare(strict_types=1);

namespace Phalanx\Dory\Build\Verify;

use Phalanx\Dory\Build\BuildProfileDefinition;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

interface VerifyCheck
{
    public string $name { get; }

    public string $description { get; }

    public function check(TaskScope&TaskExecutor $scope, string $binaryPath, BuildProfileDefinition $profile): VerifyResult;
}
