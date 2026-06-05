<?php

declare(strict_types=1);

namespace Phalanx\Demos\Console\BasicCommands;

use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Command\DescribesCommand;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

/**
 * No args, no options. Prints a fixed identity line.
 */
final class VersionCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(description: 'Print the demo version banner.');
    }

    public function __invoke(CommandContext $ctx): int
    {
        $ctx->service(StreamOutput::class)->persist('console-demo 0.2.0-alpha');

        return 0;
    }
}
