<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Command\Opt;
use Phalanx\Archon\Console\Style\ConsoleServiceBundle;
use Phalanx\Demos\Archon\RuntimeLifecycle\WatchCommand;

exit(Archon::starting(['argv' => $argv])
    ->providers(new ConsoleServiceBundle())
    ->commands(CommandGroup::of([
        'watch' => [
            WatchCommand::class,
            new CommandConfig(
                description: 'Long-running watcher with three supervised ticker workers.',
                options:     [
                    Opt::value('duration',    '', 'Seconds before completing normally.'),
                    Opt::value('fail-worker', '', 'Inject failure into ticker N (1-3).'),
                ],
            ),
        ],
    ]))
    ->run());
