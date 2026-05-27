<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\BasicCommands;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

/**
 * No args, no options. Prints a fixed identity line.
 */
final class VersionCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $ctx->service(StreamOutput::class)->persist('archon-demo 0.2.0-alpha');

        return 0;
    }
}
