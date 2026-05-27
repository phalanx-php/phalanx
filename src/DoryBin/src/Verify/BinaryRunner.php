<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Verify;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\System\StreamingProcess;

final class BinaryRunner
{
    public static function capture(TaskScope&TaskExecutor $scope, string $binaryPath, string $code, string $closeReason = 'verify.completed'): ?string
    {
        $handle = StreamingProcess::command([$binaryPath, '-r', $code])->start($scope);
        $stdout = '';

        try {
            while (true) {
                $stdout .= $handle->getIncrementalOutput();

                $exitCode = $handle->wait(0.01);
                if ($exitCode !== null) {
                    $stdout .= $handle->getIncrementalOutput();
                    $handle->close($closeReason);
                    return $exitCode === 0 ? trim($stdout) : null;
                }
            }
        } catch (Cancelled $e) {
            $handle->kill();
            throw $e;
        } catch (\Throwable) {
            $handle->kill();
            return null;
        }
    }
}
