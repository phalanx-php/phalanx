<?php

declare(strict_types=1);

namespace Phalanx\Console\Command\Config;

use Phalanx\Console\Command\CommandGroup;

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
