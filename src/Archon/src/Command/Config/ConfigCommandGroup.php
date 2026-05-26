<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command\Config;

use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Command\Opt;

/**
 * Built-in Archon commands for config inspection and environment scaffolding.
 *
 * Wired automatically by ArchonBuilder so every Archon application gains:
 *   config:list    — display all registered config classes and their env keys
 *   config:doctor  — validate the current environment against all config classes
 *   env:example    — generate or update a .env.example file from config classes
 */
final class ConfigCommandGroup
{
    public static function commands(): CommandGroup
    {
        return CommandGroup::of([
            'config:list' => [
                ConfigListCommand::class,
                new CommandConfig(
                    description: 'List all registered config classes and their env keys.',
                ),
            ],
            'config:doctor' => [
                ConfigDoctorCommand::class,
                new CommandConfig(
                    description: 'Validate the current environment against all registered config classes.',
                    options: [
                        Opt::flag(name: 'strict', desc: 'Treat warnings as boot-blockers.'),
                    ],
                ),
            ],
            'env:example' => [
                EnvExampleCommand::class,
                new CommandConfig(
                    description: 'Generate or update a .env.example file from registered config classes.',
                    options: [
                        Opt::flag(name: 'dry-run', desc: 'Print to stdout instead of writing to disk.'),
                        Opt::value(name: 'output', desc: 'Output path (default: .env.example).', default: '.env.example'),
                    ],
                ),
            ],
        ]);
    }
}
