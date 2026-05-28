<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Verify;

use Phalanx\DoryBin\BuildProfileDefinition;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class FiberContextCheck implements VerifyCheck
{
    private(set) string $name = 'fiber-context';

    private(set) string $description = 'Verify Swoole coroutine fiber context works in the built binary';

    public function check(TaskScope&TaskExecutor $scope, string $binaryPath, BuildProfileDefinition $profile): VerifyResult
    {
        $output = BinaryRunner::capture(
            $scope,
            $binaryPath,
            <<<'PHP'
if (!extension_loaded('swoole')) {
    exit(2);
}

\Swoole\Coroutine::set(['use_fiber_context' => true]);
\Swoole\Coroutine\run(static function (): void {
    \Swoole\Coroutine::getContext()['phalanx_fiber_context'] = 'ok';
    echo \Swoole\Coroutine::getContext()['phalanx_fiber_context'] ?? 'missing';
});
PHP,
            'verify.fiber-context.completed',
        );

        if ($output === null) {
            return new VerifyResult($this->name, false, 'Failed to execute Swoole fiber-context proof in binary');
        }

        if ($output !== 'ok') {
            return new VerifyResult(
                $this->name,
                false,
                "Swoole fiber context proof failed (got: '{$output}')",
            );
        }

        return new VerifyResult($this->name, true, 'Swoole fiber context is functional');
    }
}
