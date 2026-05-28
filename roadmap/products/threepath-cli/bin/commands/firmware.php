<?php

declare(strict_types=1);

use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\Opt;
use ThreePath\Command\FirmwareWatchCommand;

return CommandGroup::of([
    'firmware:watch' => [FirmwareWatchCommand::class, new CommandConfig(
        description: 'Watch for firmware changes and run integration tests automatically',
        options: [
            Opt::value('subnet', 's', 'Subnet to scan (default: STB_DEFAULT_SUBNET)'),
            Opt::value('interval', 'i', 'Poll interval in seconds (default: 300)'),
        ],
    )],
]);
