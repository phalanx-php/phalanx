#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/DockerBundle.php';
require __DIR__ . '/Commands/PsCommand.php';
require __DIR__ . '/Commands/ImagesCommand.php';
require __DIR__ . '/Commands/PullCommand.php';
require __DIR__ . '/Commands/InspectCommand.php';
require __DIR__ . '/Commands/LogsCommand.php';

use Convoy\Application;
use Convoy\Console\CommandGroup;
use Convoy\Console\ConsoleRunner;
use Convoy\Console\Examples\Commands\ImagesCommand;
use Convoy\Console\Examples\Commands\InspectCommand;
use Convoy\Console\Examples\Commands\LogsCommand;
use Convoy\Console\Examples\Commands\PsCommand;
use Convoy\Console\Examples\Commands\PullCommand;

$commands = CommandGroup::of([
    'ps'      => new PsCommand(),
    'images'  => new ImagesCommand(),
    'pull'    => new PullCommand(),
    'inspect' => new InspectCommand(),
    'logs'    => new LogsCommand(),
]);

$app = Application::starting()
    ->providers(new DockerBundle())
    ->compile();

$runner = ConsoleRunner::withCommands($app, $commands);

exit($runner->run($_SERVER['argv']));
