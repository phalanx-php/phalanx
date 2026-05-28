<?php

declare(strict_types=1);

namespace BgAgents;

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
                    $argv = $_SERVER['argv'] ?? [];
                    if (count($argv) <= 1) {
                        $argv = [...$argv, 'repl'];
                    }
                    return $this->runner->run($argv);
                }
            };
        }

        return parent::getRunner($application);
    }
}
