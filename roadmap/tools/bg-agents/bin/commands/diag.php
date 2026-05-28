<?php

declare(strict_types=1);

use BgAgents\Command\DiagDaemon8Command;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;

return CommandGroup::of([
    'diag' => [DiagDaemon8Command::class, new CommandConfig(
        description: 'Health-check the daemon8 connection',
    )],
]);
