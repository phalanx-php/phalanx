<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Console\Style\ConsoleServiceBundle;
use Phalanx\Archon\Examples\InteractiveInput\RegisterCommand;
use Phalanx\Archon\Examples\InteractiveInput\SetConfigCommand;
use Phalanx\Archon\Examples\InteractiveInput\ShowConfigCommand;

exit(Archon::starting(['argv' => $argv])
    ->providers(new ConsoleServiceBundle())
    ->commands(CommandGroup::of([
        'register' => [
            RegisterCommand::class,
            new CommandConfig(description: 'Register a demo account through interactive prompts.'),
        ],
        'config' => CommandGroup::of([
            'show' => [
                ShowConfigCommand::class,
                new CommandConfig(description: 'Display the current demo config.'),
            ],
            'set' => [
                SetConfigCommand::class,
                new CommandConfig(
                    description: 'Set a config value.',
                    arguments:   [
                        Arg::required('key',   'Config key.'),
                        Arg::required('value', 'New value.'),
                    ],
                ),
            ],
        ], description: 'Demo configuration commands.'),
    ]))
    ->run());
