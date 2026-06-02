<?php

declare(strict_types=1);

namespace Phalanx\Archon\Application;

use Symfony\Component\Runtime\RunnerInterface;

/**
 * Symfony Runtime adapter for ArchonApplication. The Symfony runtime calls
 * run() once at the end of bootstrap and uses the integer return as the
 * process exit code. ArchonApplication owns argv parsing, scope creation,
 * and dispatch — this class only forwards.
 */
final readonly class ArchonRuntimeRunner implements RunnerInterface
{
    public function __construct(private ArchonApplication $application)
    {
    }

    public function run(): int
    {
        return $this->application->run();
    }
}
