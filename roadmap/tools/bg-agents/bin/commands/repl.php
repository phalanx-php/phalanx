<?php

declare(strict_types=1);

use BgAgents\Command\TeamStartCommand;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;

return CommandGroup::of([
    'repl' => [TeamStartCommand::class, new CommandConfig(
        description: 'Open the bg-agents REPL with team auto-started (default command)',
    )],
]);
