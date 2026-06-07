<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Fixtures\Commands;

use Phalanx\Console\Command\Arg;
use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\DescribesCommand;
use Phalanx\Console\Command\CommandContext;
use Phalanx\Task\Scopeable;

final class ScanCommand implements Scopeable, DescribesCommand
{
    public static ?string $lastTarget = null;

    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Scan network',
            arguments: [Arg::required('target', 'CIDR range')],
        );
    }

    public function __invoke(CommandContext $ctx): int
    {
        self::$lastTarget = $ctx->args->get('target');
        return 0;
    }
}
