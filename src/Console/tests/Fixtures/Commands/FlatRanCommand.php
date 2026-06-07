<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Fixtures\Commands;

use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\DescribesCommand;
use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;

/**
 * Test fixture: sets a static flag when invoked. Used to assert the
 * dispatcher routed here vs. another command in the same group.
 */
final class FlatRanCommand implements Scopeable, DescribesCommand
{
    public static bool $ran = false;

    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(description: 'Start server');
    }

    public function __invoke(Scope $scope): int
    {
        self::$ran = true;
        return 0;
    }
}
