<?php

declare(strict_types=1);

namespace Phalanx\Demos\Console\InteractiveInput;

use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Command\DescribesCommand;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

/**
 * Subcommand under the `config` group. Shows that nested CommandGroups work
 * end-to-end via the dispatcher.
 */
final class ShowConfigCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(description: 'Display the current demo config.');
    }

    public function __invoke(CommandContext $ctx): int
    {
        $ctx->service(StreamOutput::class)->persist(
            "config:",
            "  endpoint = https://console.local",
            "  retries  = 3",
        );

        return 0;
    }
}
