<?php

declare(strict_types=1);

use BgAgents\Command\BookkeeperListCommand;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;

return CommandGroup::of([
    'bookkeeper' => [BookkeeperListCommand::class, new CommandConfig(
        description: 'List pending bookkeeper issues from the daemon8 blackboard',
    )],
]);
