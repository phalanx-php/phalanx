<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Verify;

use Phalanx\DoryBin\BuildProfileDefinition;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class SmokeTestCheck implements VerifyCheck
{
    private(set) string $name = 'smoke-test';

    private(set) string $description = 'Verify the binary executes PHP without error';

    public function check(TaskScope&TaskExecutor $scope, string $binaryPath, BuildProfileDefinition $profile): VerifyResult
    {
        $output = BinaryRunner::capture($scope, $binaryPath, "echo 'dory-smoke-ok';", 'verify.smoke-test.completed');

        if ($output === null) {
            return new VerifyResult($this->name, false, 'Binary failed to execute or returned non-zero exit code');
        }

        if (!str_contains($output, 'dory-smoke-ok')) {
            return new VerifyResult($this->name, false, "Expected 'dory-smoke-ok' in output, got: " . substr($output, 0, 100));
        }

        return new VerifyResult($this->name, true, 'Binary executed successfully');
    }
}
