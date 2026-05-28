<?php

declare(strict_types=1);

namespace Phalanx\Dory\Command;

use Phalanx\Archon\Command\CommandGroup;

final class DoryCommandGroup
{
    public static function commands(): CommandGroup
    {
        $commands = [
            'run' => RunCommand::class,
            'init' => InitCommand::class,
            'doctor' => DoctorCommand::class,
        ];

        if (class_exists(\Phalanx\DoryBin\Command\BuildCommandGroup::class)) {
            $commands['build'] = \Phalanx\DoryBin\Command\BuildCommandGroup::commands();
        }

        if (class_exists(\Phalanx\Skopos\FileWatcher::class)) {
            $commands['serve'] = ServeCommand::class;
        }

        return CommandGroup::of($commands);
    }
}
