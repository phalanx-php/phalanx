<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Support;

use Closure;
use Phalanx\Console\Console;
use Phalanx\Console\Application\Builder;
use Phalanx\Console\Command\CommandConfig;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Testing\PhalanxTestCase;

abstract class TestCase extends PhalanxTestCase
{
    /** @param array<string, mixed> $context */
    protected static function console(array $context = []): Builder
    {
        return Console::starting($context);
    }

    protected static function consoleCommand(
        string $name,
        Closure|Scopeable|Executable $handler,
        ?CommandConfig $config = null,
    ): Builder {
        return Console::command($name, $handler, $config);
    }
}
