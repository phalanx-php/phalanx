<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Command;

use Phalanx\Archon\Command\CommandGroup;

final class BuildCommandGroup
{
    public static function commands(): CommandGroup
    {
        return CommandGroup::of([
            'binary' => BuildBinaryCommand::class,
            'doctor' => BuildDoctorCommand::class,
            'profiles' => BuildProfilesCommand::class,
            'clean' => BuildCleanCommand::class,
        ], description: 'Static binary build pipeline');
    }
}
