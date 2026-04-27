#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Phalanx\Application;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\ConsoleRunner;
use Phalanx\Archon\Style\ConsoleServiceBundle;
use Phalanx\Athena\AiServiceBundle;
use Phx\Agents\BrainstormCommand;
use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
if (file_exists(__DIR__ . '/.env')) {
    $dotenv->load(__DIR__ . '/.env');
}

$context = $_ENV;

$app = Application::starting($context)
    ->providers(
        new ConsoleServiceBundle(),
        new AiServiceBundle(),
    )
    ->compile();

$runner = ConsoleRunner::withHandlers(
    $app,
    CommandGroup::of(['brainstorm' => BrainstormCommand::class]),
);

exit($runner->run(['run.php', 'brainstorm']));
