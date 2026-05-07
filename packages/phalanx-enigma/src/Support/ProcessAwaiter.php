<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Support;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Enigma\Exception\SshTimeoutException;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\System\StreamingProcess;
use Throwable;

final class ProcessAwaiter
{
    /**
     * @param non-empty-list<string> $argv
     * @return array{int, string, string, float} [exitCode, stdout, stderr, durationMs]
     */
    public static function spawn(array $argv, TaskScope&TaskExecutor $scope, ?float $timeoutSeconds = null): array
    {
        $start = hrtime(true);
        $stdout = '';
        $stderr = '';
        $deadline = $timeoutSeconds === null ? null : microtime(true) + max(0.0, $timeoutSeconds);
        $handle = StreamingProcess::command($argv)->start($scope);

        try {
            while (true) {
                $stdout .= $handle->getIncrementalOutput();
                $stderr .= $handle->getIncrementalErrorOutput();

                if ($deadline !== null && microtime(true) >= $deadline) {
                    $handle->kill();
                    throw new SshTimeoutException(
                        sprintf('Process timed out after %.3f seconds', $timeoutSeconds),
                        stderr: $stderr,
                    );
                }

                $exitCode = $handle->wait(0.01);
                if ($exitCode !== null) {
                    break;
                }
            }

            $stdout .= $handle->getIncrementalOutput();
            $stderr .= $handle->getIncrementalErrorOutput();
            $handle->close('enigma.process.completed');
        } catch (Cancelled $e) {
            $handle->kill();
            throw $e;
        } catch (Throwable $e) {
            $handle->kill();
            throw $e;
        }

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        return [$exitCode, $stdout, $stderr, $durationMs];
    }

    /**
     * @param list<string> $args
     * @return non-empty-list<string>
     */
    public static function argv(string $binary, array $args): array
    {
        return [$binary, ...$args];
    }
}
