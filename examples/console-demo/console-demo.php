#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/DemoCommand.php';

use Phalanx\Application;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\ConsoleRunner;
use Phalanx\Archon\Demo\DemoCommand;
use Phalanx\Archon\Style\ConsoleServiceBundle;

$app = Application::starting()
    ->providers(new ConsoleServiceBundle())
    ->compile();

$runner = ConsoleRunner::withHandlers(
    $app,
    CommandGroup::of(['demo' => new DemoCommand()]),
);

exit($runner->run(['console-demo', 'demo']));
