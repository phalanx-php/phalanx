<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Support;

use Closure;
use Phalanx\Console\Application\Console;
use Phalanx\Console\Application\ConsoleBuilder;
use Phalanx\Console\Command\CommandConfig;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Testing\PhalanxTestCase;

abstract class ConsoleTestCase extends PhalanxTestCase
{
    /** @param array<string, mixed> $context */
    protected static function console(array $context = []): ConsoleBuilder
    {
        return Console::starting($context);
    }

    protected static function consoleCommand(
        string $name,
        Closure|Scopeable|Executable $handler,
        ?CommandConfig $config = null,
    ): ConsoleBuilder {
        return Console::command($name, $handler, $config);
    }
}
