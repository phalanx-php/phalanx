<?php

declare(strict_types=1);

namespace ThreePath;

use Phalanx\Archon\ConsoleRunner;
use React\EventLoop\Loop;
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
                    $exitCode = $this->runner->run($_SERVER['argv'] ?? []);
                    Loop::stop();
                    return $exitCode;
                }
            };
        }

        return parent::getRunner($application);
    }
}
