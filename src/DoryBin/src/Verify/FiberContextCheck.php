<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Verify;

use Phalanx\DoryBin\BuildProfileDefinition;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class FiberContextCheck implements VerifyCheck
{
    private(set) string $name = 'fiber-context';

    // NOTE: swoole.use_fiber_context INI key needs verification against swoole-src;
    // swoole sets fiber context via Coroutine::set() at runtime, not a static INI flag.
    private(set) string $description = 'Verify swoole.use_fiber_context is enabled in the built binary';

    public function check(TaskScope&TaskExecutor $scope, string $binaryPath, BuildProfileDefinition $profile): VerifyResult
    {
        $output = BinaryRunner::capture($scope, $binaryPath, "echo ini_get('swoole.use_fiber_context');", 'verify.fiber-context.completed');

        if ($output === null) {
            return new VerifyResult($this->name, false, 'Failed to query swoole.use_fiber_context from binary');
        }

        if ($output !== '1') {
            return new VerifyResult(
                $this->name,
                false,
                "swoole.use_fiber_context is not enabled (got: '{$output}')",
            );
        }

        return new VerifyResult($this->name, true, 'Fiber context is enabled');
    }
}
