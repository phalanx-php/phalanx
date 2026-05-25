<?php

declare(strict_types=1);

namespace Phalanx\Agora\Tests\Support;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Surreal\Surreal;
use Phalanx\System\StreamingProcessHandle;

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
