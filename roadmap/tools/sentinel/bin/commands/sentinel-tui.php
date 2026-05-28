<?php

declare(strict_types=1);

use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\Opt;
use Sentinel\SentinelTuiCommand;

return CommandGroup::of([
    'sentinel-tui' => [SentinelTuiCommand::class, new CommandConfig(
        description: 'Watch a project with expert AI agents (terminal UI mode)',
        arguments: [
            Arg::optional('project', 'Path to the project directory to watch'),
        ],
        options: [
            Opt::value('preset', 'p', 'Persona preset (php, react-native, tv, core, full)'),
            Opt::value('persona', '', 'Comma-separated persona names to load'),
            Opt::flag('list-presets', 'l', 'List available presets'),
            Opt::flag('list-personas', '', 'List all available personas'),
        ],
    )],
]);
