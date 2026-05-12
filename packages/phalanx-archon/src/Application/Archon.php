<?php

declare(strict_types=1);

namespace Phalanx\Archon\Application;

use Closure;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Boot\AppContext;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

final class Archon
{
    /** @param array<string,mixed> $context */
    public static function starting(array $context = []): ArchonBuilder
    {
        if (!isset($context['argv'])) {
            $context['argv'] = $_SERVER['argv'] ?? [];
        }

        return new ArchonBuilder(new AppContext($context));
    }

    public static function command(
        string $name,
        Closure|Scopeable|Executable $handler,
        ?CommandConfig $config = null,
    ): ArchonBuilder {
        return self::starting()->command($name, $handler, $config);
    }
}
