<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\ExampleCommand;
use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;

var_dump($_SERVER['argv']);

$commands = CommandGroup::of([
    'example' => [
        ExampleCommand::class,
        new CommandConfig(description: 'An example command.'),
    ],
]);

exit(Archon::starting()
    ->commands($commands)
    ->build()
    ->run());