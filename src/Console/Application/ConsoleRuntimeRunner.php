<?php

declare(strict_types=1);

namespace Phalanx\Console\Application;

use Symfony\Component\Runtime\RunnerInterface;

/**
 * Symfony Runtime adapter for ConsoleApplication. The Symfony runtime calls
 * run() once at the end of bootstrap and uses the integer return as the
 * process exit code. ConsoleApplication owns argv parsing, scope creation,
 * and dispatch — this class only forwards.
 */
final readonly class ConsoleRuntimeRunner implements RunnerInterface
{
    public function __construct(private ConsoleApplication $application)
    {
    }

    public function run(): int
    {
        return $this->application->run();
    }
}
