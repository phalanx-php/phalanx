<?php

declare(strict_types=1);

namespace Phalanx\Dory\Command;

use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;

final class DoryCommandGroup
{
    public static function commands(): CommandGroup
    {
        $commands = [
            'run' => [
                RunCommand::class,
                new CommandConfig(
                    description: 'Run a Dory script',
                    arguments: [
                        Arg::required('script', 'Path to the Dory script'),
                    ],
                ),
            ],
            'init' => [
                InitCommand::class,
                new CommandConfig(
                    description: 'Initialize a new Dory project',
                    arguments: [
                        Arg::optional('directory', 'Target directory', '.'),
                    ],
                ),
            ],
            'doctor' => [
                DoctorCommand::class,
                new CommandConfig(
                    description: 'Check environment readiness',
                ),
            ],
        ];

        if (class_exists(\Phalanx\Skopos\FileWatcher::class)) {
            $commands['serve'] = [
                ServeCommand::class,
                new CommandConfig(
                    description: 'Run and watch a Dory script for changes',
                    arguments: [
                        Arg::required('script', 'Path to the Dory script'),
                    ],
                ),
            ];
        }

        return CommandGroup::of($commands);
    }
}
