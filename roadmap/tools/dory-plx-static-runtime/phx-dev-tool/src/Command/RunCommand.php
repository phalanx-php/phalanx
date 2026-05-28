<?php

declare(strict_types=1);

namespace Phx\Command;

use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

final class RunCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);

        $script = (string) $ctx->args->required('script');

        if (!file_exists($script)) {
            $output->persist("<error>Script not found: {$script}</error>");
            return 1;
        }

        $GLOBALS['dory'] = $ctx;

        try {
            $scriptPath = realpath($script);
            require $scriptPath;
            return 0;
        } catch (\Throwable $e) {
            $output->persist("<error>Execution failed: " . $e->getMessage() . "</error>");
            $output->persist($e->getTraceAsString());
            return 1;
        }
    }

    public static function config(): CommandConfig
    {
        return new CommandConfig(
            description: 'Runs a Phalanx script with zero ceremony.',
            args: [
                Arg::required('script', 'The PHP script to run.'),
            ],
        );
    }
}
