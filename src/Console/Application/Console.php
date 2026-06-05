<?php

declare(strict_types=1);

namespace Phalanx\Console\Application;

use Closure;
use Phalanx\Boot\AppContext;
use Phalanx\Console\Command\CommandConfig;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

final class Console
{
    /** @param array<string,mixed> $context */
    public static function starting(array $context = []): ConsoleBuilder
    {
        return new ConsoleBuilder(new AppContext($context));
    }

    public static function command(
        string $name,
        Closure|Scopeable|Executable $handler,
        ?CommandConfig $config = null,
    ): ConsoleBuilder {
        return self::starting()->command($name, $handler, $config);
    }
}
