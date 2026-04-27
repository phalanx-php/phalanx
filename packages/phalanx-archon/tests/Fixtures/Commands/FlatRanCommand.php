<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Fixtures\Commands;

use Phalanx\Scope;
use Phalanx\Task\Scopeable;

/**
 * Test fixture: sets a static flag when invoked. Used to assert the
 * dispatcher routed here vs. another command in the same group.
 */
final class FlatRanCommand implements Scopeable
{
    public static bool $ran = false;

    public function __invoke(Scope $scope): int
    {
        self::$ran = true;
        return 0;
    }
}
