<?php

declare(strict_types=1);

namespace Phalanx\Archon\Application;

use Symfony\Component\Runtime\RunnerInterface;

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
