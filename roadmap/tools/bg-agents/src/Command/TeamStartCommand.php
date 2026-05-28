<?php

declare(strict_types=1);

namespace BgAgents\Command;

use BgAgents\Repl\ReplPrinter;
use BgAgents\Repl\ReplRunner;
use BgAgents\Team\TeamRunner;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Task;

/**
 * Default user-facing command. Spawns the TeamRunner alongside the REPL
 * so they share the parent scope: the REPL exit disposes the parent,
 * which cleanly shuts down all team lanes.
 */
final class TeamStartCommand implements Executable
{
    public function __invoke(ExecutionScope $scope): int
    {
        $printer = $scope->service(ReplPrinter::class);
        $printer->banner('starting bg-agents team');

        $scope->concurrent([
            Task::of(static fn(ExecutionScope $s): mixed => $s->execute(new TeamRunner())),
            Task::of(static fn(ExecutionScope $s): int => $s->execute(new ReplRunner())),
        ]);

        return 0;
    }
}
