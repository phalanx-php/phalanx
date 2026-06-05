<?php

declare(strict_types=1);

namespace Phalanx\Demos\Console\BasicCommands;

use Phalanx\Console\Command\Arg;
use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Command\DescribesCommand;
use Phalanx\Console\Console\Output\StreamOutput;
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
