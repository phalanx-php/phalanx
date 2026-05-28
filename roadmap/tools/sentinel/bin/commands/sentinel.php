<?php

declare(strict_types=1);

use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\Opt;
use Sentinel\SentinelCommand;

return CommandGroup::of([
    'sentinel' => [SentinelCommand::class, new CommandConfig(
        description: 'Watch a project directory and review changes with expert AI agents',
        arguments: [
            Arg::optional('project', 'Path to the project directory to watch (default: current directory)'),
        ],
        options: [
            Opt::value('preset', 'p', 'Persona preset: php, react-native, tv, core, full'),
            Opt::value('persona', '', 'Comma-separated persona names (e.g. architect,security)'),
            Opt::flag('list-presets', 'l', 'List available presets and their personas'),
            Opt::flag('list-personas', '', 'List all available persona files'),
            Opt::flag('help', 'h', 'Show usage examples and interactive commands'),
        ],
    )],
]);
