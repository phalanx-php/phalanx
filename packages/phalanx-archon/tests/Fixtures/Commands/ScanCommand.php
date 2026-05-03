<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Fixtures\Commands;

use Phalanx\Archon\Command\CommandScope;
use Phalanx\Task\Scopeable;

final class ScanCommand implements Scopeable
{
    public static ?string $lastTarget = null;

    public function __invoke(CommandScope $scope): int
    {
        self::$lastTarget = $scope->args->get('target');
        return 0;
    }
}
