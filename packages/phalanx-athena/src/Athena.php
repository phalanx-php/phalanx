<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Closure;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

final readonly class Athena
{
    private function __construct()
    {
    }

    /** @param array<string, mixed> $context */
    public static function starting(array $context = []): AthenaApplicationBuilder
    {
        return new AthenaApplicationBuilder($context);
    }

    /** @param array<string, mixed> $context */
    public static function run(
        Closure|Scopeable|Executable $task,
        array $context = [],
    ): mixed {
        return self::starting($context)->run($task);
    }
}
