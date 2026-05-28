<?php

declare(strict_types=1);

namespace Sentinel;

use Phalanx\Archon\ConsoleRunner;
use Symfony\Component\Runtime\GenericRuntime;
use Symfony\Component\Runtime\RunnerInterface;

final class ConsoleRuntime extends GenericRuntime
{
    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof ConsoleRunner) {
            return new class($application) implements RunnerInterface {
                public function __construct(private readonly ConsoleRunner $runner) {}

                public function run(): int
                {
                    return $this->runner->run($_SERVER['argv'] ?? []);
                }
            };
        }

        return parent::getRunner($application);
    }
}
