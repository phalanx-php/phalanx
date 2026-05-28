<?php

declare(strict_types=1);

use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use ThreePath\Command\ScanCommand;

return CommandGroup::of([
    'scan' => [ScanCommand::class, new CommandConfig(
        description: 'Scan a subnet for STBs',
        arguments: [
            Arg::optional('cidr', 'CIDR range to scan (default: STB_DEFAULT_SUBNET)'),
        ],
    )],
]);
