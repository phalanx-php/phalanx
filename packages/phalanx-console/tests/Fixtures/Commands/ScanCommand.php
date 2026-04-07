<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Fixtures\Commands;

use Phalanx\Console\CommandScope;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;

/**
 * Test fixture: reads the 'target' arg from the command scope and records it
 * in a static property. Tests reset and read the static value to verify the
 * dispatcher routed to this command and the arg parser worked.
 */
final class ScanCommand implements Scopeable
{
    public static ?string $lastTarget = null;

    public function __invoke(Scope $scope): int
    {
        assert($scope instanceof CommandScope);
        self::$lastTarget = $scope->args->get('target');
        return 0;
    }
}
