<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\BasicCommands;

use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

/**
 * Smallest possible command. Reads a required positional arg and writes a line.
 */
final class GreetCommand implements Scopeable
{
    public function __invoke(CommandScope $scope): int
    {
        $name = (string) $scope->args->required('name');

        $scope->service(StreamOutput::class)->persist("Hello, {$name}.");

        return 0;
    }
}
