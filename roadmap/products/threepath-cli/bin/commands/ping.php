<?php

declare(strict_types=1);

use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use ThreePath\Command\PingCommand;

return CommandGroup::of([
    'ping' => [PingCommand::class, new CommandConfig(
        description: 'Ping a single STB via HELLO_DISCOVERY',
        arguments: [
            Arg::optional('ip', 'IP address (default: STB_DEFAULT_DEVICE_IP)'),
        ],
    )],
]);
