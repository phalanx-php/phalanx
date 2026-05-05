<?php

declare(strict_types=1);

namespace Acme\ArchonDemo\Concurrency\Stages;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use RuntimeException;

/**
 * Flaky stage: throws on its first two attempts, succeeds on the third.
 * Wrapped with $scope->retry(...) by the deploy command so the failure
 * surface is visible without crashing the batch.
 */
final class TestStage implements Executable
{
    public static int $attempts = 0;

    public function __invoke(ExecutionScope $scope): string
    {
        $scope->delay(0.35);

        self::$attempts++;
        if (self::$attempts < 3) {
            throw new RuntimeException("transient failure on attempt " . self::$attempts);
        }

        return 'test: 184/184 passed';
    }
}
