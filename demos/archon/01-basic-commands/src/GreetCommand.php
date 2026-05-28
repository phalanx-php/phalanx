<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\BasicCommands;

use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\DescribesCommand;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

/**
 * Smallest possible command. Reads a required positional arg and writes a line.
 */
final class GreetCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Greet someone by name.',
            arguments: [Arg::required('name', 'Person to greet.')],
        );
    }

    public function __invoke(CommandContext $ctx): int
    {
        $name = (string) $ctx->args->required('name');

        $ctx->service(StreamOutput::class)->persist("Hello, {$name}.");

        return 0;
    }
}
