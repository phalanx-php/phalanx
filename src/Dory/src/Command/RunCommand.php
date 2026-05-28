<?php

declare(strict_types=1);

namespace Phalanx\Dory\Command;

use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\DescribesCommand;
use Phalanx\Dory\DoryBuilder;
use Phalanx\Task\Scopeable;

final class RunCommand implements Scopeable, DescribesCommand
{
    public function __invoke(CommandContext $ctx): int
    {
        $scriptPath = (string) $ctx->args->required('script');
        $resolved = realpath($scriptPath);

        if ($resolved === false || !file_exists($resolved)) {
            fwrite(STDERR, "Script not found: {$scriptPath}\n");
            return 1;
        }

        $builder = new DoryBuilder();
        $builder->script($resolved);

        return $builder->run();
    }

    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Run a Dory script',
            arguments: [
                Arg::required('script', 'Path to the Dory script'),
            ],
        );
    }
}
