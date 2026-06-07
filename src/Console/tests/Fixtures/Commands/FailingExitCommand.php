<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Fixtures\Commands;

use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\DescribesCommand;
use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;

final class FailingExitCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(description: 'Exit non-zero.');
    }

    public function __invoke(Scope $scope): int
    {
        return 7;
    }
}
