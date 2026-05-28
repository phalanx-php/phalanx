<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\InteractiveInput;

use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\DescribesCommand;
use Phalanx\Archon\Console\Output\StreamOutput;
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
            "  endpoint = https://archon.local",
            "  retries  = 3",
        );

        return 0;
    }
}
