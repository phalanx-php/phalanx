<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command\Config;

use Phalanx\Archon\Command\CommandGroup;

final class ConfigCommandGroup
{
    public static function commands(): CommandGroup
    {
        return CommandGroup::of([
            'config:list' => ConfigListCommand::class,
            'config:doctor' => ConfigDoctorCommand::class,
            'env:example' => EnvExampleCommand::class,
        ]);
    }
}
