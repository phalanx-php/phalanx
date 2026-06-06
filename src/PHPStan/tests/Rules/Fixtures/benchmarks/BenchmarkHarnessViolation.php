<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\Benchmarks;

use Phalanx\Application;
use Phalanx\Console\Console;
use Phalanx\Http\Server;

/**
 * Fixture path contains /benchmarks/, so direct entry booting should be
 * rejected in favor of the benchmark harness.
 */
final class BenchmarkHarnessViolation
{
    public function bareApplication(): void
    {
        Application::starting()->compile();
    }

    public function httpFacade(): void
    {
        Server::starting()->build();
    }

    public function consoleFacade(): void
    {
        Console::starting()->build();
    }
}
