<?php

declare(strict_types=1);

namespace Phalanx\Archon\Examples\BasicCommands;

use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

/**
 * No args, no options. Prints a fixed identity line.
 */
final class VersionCommand implements Scopeable
{
    public function __invoke(CommandScope $scope): int
    {
        $scope->service(StreamOutput::class)->persist('archon-demo 0.1');

        return 0;
    }
}
