<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload_runtime.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phx\Command\ServeCommand;
use Phx\Command\BuildCommand;
use Phx\Command\InitCommand;
use Phx\Command\DoctorCommand;
use Phx\Command\RunCommand;

return static fn(array $context): \Closure => static function () use ($context): int {
    $commands = CommandGroup::of([
        'init' => [
            InitCommand::class,
            new CommandConfig(description: 'Initializes a new Phalanx project.'),
        ],
        'serve' => [
            ServeCommand::class,
            new CommandConfig(description: 'Starts the development server with HMR.'),
        ],
        'build' => [
            BuildCommand::class,
            new CommandConfig(description: 'Builds a static binary for the project.'),
        ],
        'doctor' => [
            DoctorCommand::class,
            new CommandConfig(description: 'Checks the environment for Phalanx readiness.'),
        ],
        'run' => [
            RunCommand::class,
            new CommandConfig(description: 'Runs a Phalanx script with zero ceremony.'),
        ],
    ]);

    $context = array_merge($context, [
        'phalanx.runtime.memory' => [
            'resource_rows' => 16384,
            'event_rows' => 16384,
            'edge_rows' => 16384,
        ],
    ]);

    // Handle one-shot script execution as default command
    $argv = $context['argv'] ?? $_SERVER['argv'] ?? [];
    if (count($argv) > 1 && !str_starts_with($argv[1], '-') && file_exists($argv[1])) {
        // If first arg is a file, default to 'run'
        array_splice($argv, 1, 0, 'run');
        $context['argv'] = $argv;
    }

    return Archon::starting($context)
        ->commands($commands)
        ->build()
        ->run();
};
