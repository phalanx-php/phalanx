<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\BasicCommands;

use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\DescribesCommand;
use Phalanx\Archon\Command\Opt;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

/**
 * Reads a boolean flag, conditionally uppercases the body. Demonstrates the
 * Opt::flag option surface and CommandContext::$options accessor.
 */
final class InfoCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Print build info; --shout uppercases the body.',
            options: [Opt::flag('shout', 's', 'Uppercase the body.')],
        );
    }

    public function __invoke(CommandContext $ctx): int
    {
        $body = "phalanx archon\nphp 8.4 + swoole\nstatus: ready";

        if ($ctx->options->flag('shout')) {
            $body = mb_strtoupper($body);
        }

        $ctx->service(StreamOutput::class)->persist($body);

        return 0;
    }
}
