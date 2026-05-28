<?php

declare(strict_types=1);

use BgAgents\Command\TeamStartCommand;
use BgAgents\Command\TeamStatusCommand;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;

return CommandGroup::of([
    'team' => [TeamStartCommand::class, new CommandConfig(
        description: 'Start the bg-agents team (REPL + supervisor + heartbeat)',
    )],
    'status' => [TeamStatusCommand::class, new CommandConfig(
        description: 'Show team status from the latest daemon8 heartbeat',
    )],
]);
