<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

use Phalanx\Archon\Application\ArchonApplication;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalState;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Traceable;

/** @internal */
final class ArchonDispatchTask implements Executable, Traceable
{
    public string $traceName {
        get => 'archon.application.run';
    }

    /** @param list<string> $argv */
    public function __construct(
        private ArchonApplication $application,
        private array $argv,
        private ?ConsoleSignalState $signals = null,
    ) {
    }

    public function __invoke(ExecutionScope $scope): int
    {
        return $this->application->dispatchScoped($this->argv, $scope, $this->signals);
    }
}
