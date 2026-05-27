<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

interface DescribesCommand
{
    public static function commandConfig(): CommandConfig;
}
