<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Closure;
use Phalanx\Boot\AppContext;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

final class Athena
{
    private function __construct()
    {
    }

    public static function starting(AppContext $context = new AppContext()): AthenaApplicationBuilder
    {
        return new AthenaApplicationBuilder($context);
    }

    public static function run(
        Closure|Scopeable|Executable $task,
        AppContext $context = new AppContext(),
    ): mixed {
        return self::starting($context)->run($task);
    }
}
