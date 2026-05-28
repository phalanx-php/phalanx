<?php

declare(strict_types=1);

use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\Opt;
use ThreePath\Command\StatusCommand;

return CommandGroup::of([
    'status' => [StatusCommand::class, new CommandConfig(
        description: 'Get STB tuner status',
        arguments: [
            Arg::optional('ip', 'IP address (default: STB_DEFAULT_DEVICE_IP)'),
        ],
        options: [
            Opt::value('device-id', 'd', 'Device chip ID'),
        ],
    )],
]);
