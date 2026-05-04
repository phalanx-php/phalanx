#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/DemoCommand.php';
require __DIR__ . '/AskCommand.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Command\Opt;
use Phalanx\Archon\Console\Style\ConsoleServiceBundle;
use Phalanx\Archon\Demo\AskCommand;
use Phalanx\Archon\Demo\DemoCommand;

exit(Archon::starting(['argv' => $argv])
    ->providers(new ConsoleServiceBundle())
    ->commands(CommandGroup::of([
        'demo' => [
            DemoCommand::class,
            new CommandConfig(
                description: 'Print a scoped Archon demo message.',
                arguments: [
                    Arg::optional('name', 'Name to greet.', 'operator'),
                ],
                options: [
                    Opt::flag('shout', 's', 'Uppercase the greeting.'),
                ],
            ),
        ],
        'ask' => [
            AskCommand::class,
            new CommandConfig(
                description: 'Prompt for a name and confirm the greeting.',
            ),
        ],
    ]))
    ->run());
