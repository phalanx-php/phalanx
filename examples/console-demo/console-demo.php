#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/DemoCommand.php';

use Phalanx\Application;
use Phalanx\Console\CommandGroup;
use Phalanx\Console\ConsoleRunner;
use Phalanx\Console\Demo\DemoCommand;
use Phalanx\Console\Style\ConsoleServiceBundle;

$app = Application::starting()
    ->providers(new ConsoleServiceBundle())
    ->compile();

$runner = ConsoleRunner::withHandlers(
    $app,
    CommandGroup::of(['demo' => new DemoCommand()]),
);

exit($runner->run(['console-demo', 'demo']));
