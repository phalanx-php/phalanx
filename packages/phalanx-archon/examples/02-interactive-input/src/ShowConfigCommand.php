<?php

declare(strict_types=1);

namespace Phalanx\Archon\Examples\InteractiveInput;

use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

/**
 * Subcommand under the `config` group. Shows that nested CommandGroups work
 * end-to-end via the dispatcher.
 */
final class ShowConfigCommand implements Scopeable
{
    public function __invoke(CommandScope $scope): int
    {
        $scope->service(StreamOutput::class)->persist(
            "config:",
            "  endpoint = https://archon.local",
            "  retries  = 3",
        );

        return 0;
    }
}
