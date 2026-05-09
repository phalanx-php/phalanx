<?php

declare(strict_types=1);

namespace Phalanx\Demos\Surreal\Support;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Surreal\Surreal;
use Phalanx\System\StreamingProcessHandle;

/**
 * Polls the SurrealDB health endpoint until the server reports ready or
 * the process handle signals that the server has exited.
 *
 * Returns true when the server becomes healthy; false when it exits before
 * becoming ready. Re-throws Cancelled so callers can propagate cancellation.
 */
final class SurrealServerReadiness
{
    public function __invoke(
        ExecutionScope $scope,
        Surreal $surreal,
        StreamingProcessHandle $server,
    ): bool {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (!$server->isRunning()) {
                return false;
            }

            try {
                if (in_array($surreal->health(), [200, 204], true)) {
                    return true;
                }
            } catch (Cancelled $e) {
                throw $e;
            } catch (\Throwable) {
            }

            $scope->delay(0.05);
        }

        return false;
    }
}
