<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phx\Command\RunCommand;
use Phx\Command\ServeCommand;
use Phx\Command\InitCommand;
use Phx\Command\DoctorCommand;

// We use standard execution (no symfony runtime here for the wrapper itself)
// because we want maximum control over the argument mutation before Archon boots.

$context = [
    'phalanx.runtime.memory' => [
        'resource_rows' => 16384,
        'event_rows' => 16384,
        'edge_rows' => 16384,
    ],
    'argv' => $_SERVER['argv'],
];

// Handle one-shot script execution as default command
if (count($context['argv']) > 1 && !str_starts_with($context['argv'][1], '-') && file_exists($context['argv'][1])) {
    // If first arg is a file, inject 'run' command
    array_splice($context['argv'], 1, 0, 'run');
}

$commands = CommandGroup::of([
    'run' => [
        RunCommand::class,
        RunCommand::config(),
    ],
    'serve' => [
        ServeCommand::class,
        ServeCommand::config(),
    ],    'init' => [
        InitCommand::class,
        new CommandConfig(description: 'Initializes a new Phalanx project.'),
    ],
    'doctor' => [
        DoctorCommand::class,
        new CommandConfig(description: 'Checks the environment for Phalanx readiness.'),
    ]
]);

try {
    exit(Archon::starting($context)
        ->providers(\Phalanx\Iris\Iris::services())
        ->commands($commands)
        ->build()
        ->run());
} catch (\Throwable $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
