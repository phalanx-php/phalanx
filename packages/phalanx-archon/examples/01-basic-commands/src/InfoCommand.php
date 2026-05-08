<?php

declare(strict_types=1);

namespace Phalanx\Archon\Examples\BasicCommands;

use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

/**
 * Reads a boolean flag, conditionally uppercases the body. Demonstrates the
 * Opt::flag option surface and CommandScope::$options accessor.
 */
final class InfoCommand implements Scopeable
{
    public function __invoke(CommandScope $scope): int
    {
        $body = "phalanx archon\nphp 8.4 + openswoole 26\nstatus: ready";

        if ($scope->options->flag('shout')) {
            $body = mb_strtoupper($body);
        }

        $scope->service(StreamOutput::class)->persist($body);

        return 0;
    }
}
