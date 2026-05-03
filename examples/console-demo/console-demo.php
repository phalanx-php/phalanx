#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/DemoCommand.php';

use Phalanx\Archon\Archon;
use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\Demo\DemoCommand;
use Phalanx\Archon\Opt;
use Phalanx\Archon\Style\ConsoleServiceBundle;

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
    ]))
    ->run());
