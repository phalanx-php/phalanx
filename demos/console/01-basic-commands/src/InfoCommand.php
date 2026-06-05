<?php

declare(strict_types=1);

namespace Phalanx\Demos\Console\BasicCommands;

use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Command\DescribesCommand;
use Phalanx\Console\Command\Opt;
use Phalanx\Console\Output\StreamOutput;
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
        $body = "phalanx console\nphp 8.4 + swoole\nstatus: ready";

        if ($ctx->options->flag('shout')) {
            $body = mb_strtoupper($body);
        }

        $ctx->service(StreamOutput::class)->persist($body);

        return 0;
    }
}
