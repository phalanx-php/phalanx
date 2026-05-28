<?php

declare(strict_types=1);

use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\Opt;
use ThreePath\Command\ChannelDownCommand;
use ThreePath\Command\ChannelSwitchCommand;
use ThreePath\Command\ChannelUpCommand;

return CommandGroup::of([
    'channel:switch' => [ChannelSwitchCommand::class, new CommandConfig(
        description: 'Switch STB to a specific channel/service',
        arguments: [
            Arg::optional('ip', 'IP address (default: STB_DEFAULT_DEVICE_IP)'),
            Arg::optional('service-id', 'Service ID (default: STB_DEFAULT_SERVICE_ID)'),
        ],
        options: [
            Opt::value('device-id', 'd', 'Device chip ID'),
        ],
    )],

    'channel:up' => [ChannelUpCommand::class, new CommandConfig(
        description: 'Channel up',
        arguments: [
            Arg::optional('ip', 'IP address (default: STB_DEFAULT_DEVICE_IP)'),
        ],
        options: [
            Opt::value('device-id', 'd', 'Device chip ID'),
        ],
    )],

    'channel:down' => [ChannelDownCommand::class, new CommandConfig(
        description: 'Channel down',
        arguments: [
            Arg::optional('ip', 'IP address (default: STB_DEFAULT_DEVICE_IP)'),
        ],
        options: [
            Opt::value('device-id', 'd', 'Device chip ID'),
        ],
    )],
]);
