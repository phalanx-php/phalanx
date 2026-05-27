<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Fixtures\Commands;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Task\Scopeable;

final class ScanCommand implements Scopeable
{
    public static ?string $lastTarget = null;

    public function __invoke(CommandContext $ctx): int
    {
        self::$lastTarget = $ctx->args->get('target');
        return 0;
    }
}
