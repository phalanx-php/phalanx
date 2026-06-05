<?php

declare(strict_types=1);

namespace Phalanx\Console\Command;

interface DescribesCommand
{
    public static function commandConfig(): CommandConfig;
}
