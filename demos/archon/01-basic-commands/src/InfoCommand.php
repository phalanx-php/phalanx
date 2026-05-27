<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\BasicCommands;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

/**
 * Reads a boolean flag, conditionally uppercases the body. Demonstrates the
 * Opt::flag option surface and CommandContext::$options accessor.
 */
final class InfoCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $body = "phalanx archon\nphp 8.4 + openswoole 26\nstatus: ready";

        if ($ctx->options->flag('shout')) {
            $body = mb_strtoupper($body);
        }

        $ctx->service(StreamOutput::class)->persist($body);

        return 0;
    }
}
