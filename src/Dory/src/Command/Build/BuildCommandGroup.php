<?php

declare(strict_types=1);

namespace Phalanx\Dory\Command\Build;

use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Command\Opt;

final class BuildCommandGroup
{
    public static function commands(): CommandGroup
    {
        return CommandGroup::of([
            'binary' => [
                BuildBinaryCommand::class,
                new CommandConfig(
                    description: 'Build a static Dory binary',
                    options: [
                        Opt::value('profile', 'p', 'Build profile', default: 'full'),
                        Opt::value('output', 'o', 'Output binary path'),
                        Opt::flag('clean', 'c', 'Clean build directory first'),
                        Opt::flag('verbose', 'v', 'Show build subprocess output'),
                        Opt::flag('dry-run', 'd', 'Show planned stages without executing'),
                        Opt::value('spc-path', '', 'Path to spc binary'),
                    ],
                ),
            ],
            'check' => [
                BuildCheckCommand::class,
                new CommandConfig(
                    description: 'Check build prerequisites',
                    options: [
                        Opt::value('profile', 'p', 'Build profile to check', default: 'full'),
                    ],
                ),
            ],
            'doctor' => [
                BuildDoctorCommand::class,
                new CommandConfig(
                    description: 'Diagnose a built Dory binary',
                    arguments: [
                        Arg::optional('binary', 'Path to the binary', './dory'),
                    ],
                ),
            ],
            'profiles' => [
                BuildProfilesCommand::class,
                new CommandConfig(
                    description: 'List available build profiles',
                    options: [
                        Opt::value('format', 'f', 'Output format', default: 'table'),
                    ],
                ),
            ],
            'clean' => [
                BuildCleanCommand::class,
                new CommandConfig(
                    description: 'Remove build artifacts',
                    options: [
                        Opt::flag('all', 'a', 'Remove all artifacts including downloads'),
                    ],
                ),
            ],
        ], description: 'Static binary build pipeline');
    }
}
