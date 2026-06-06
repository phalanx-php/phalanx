<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\Integration;

use Phalanx\Application;
use Phalanx\Console\Console;
use Phalanx\Http\Server;

/**
 * Fixture sitting at a path containing /Integration/ — the UseTestAppRule
 * recognizes this as test code and flags direct ::starting() calls.
 */
final class UseTestAppViolation
{
    public function bareApplication(): void
    {
        $app = Application::starting()->compile();
    }

    public function httpModuleEntry(): void
    {
        $http = Server::starting()->build();
    }

    public function consoleCommand(): void
    {
        $console = Console::command('demo', static fn(): int => 0)->build();
    }
}
