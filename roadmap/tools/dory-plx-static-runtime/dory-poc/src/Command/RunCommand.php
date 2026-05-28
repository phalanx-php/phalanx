<?php

declare(strict_types=1);

namespace Phx\Command;

use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;
use Phx\ScriptScope;

final class RunCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $script = (string) $ctx->args->required('script');

        if (!file_exists($script)) {
            echo "Error: Script not found: {$script}\n";
            return 1;
        }

        // Inject the $dory global so the script has access to the supervised scope.
        $GLOBALS['dory'] = new ScriptScope($ctx);

        try {
            $scriptPath = realpath($script);
            if ($scriptPath === false) {
                 echo "Error: Could not resolve script path: {$script}\n";
                 return 1;
            }

            // Require the script inside this try/catch.
            // Any async operations within the script will be supervised by the Aegis kernel
            // because OpenSwoole hooks are enabled and we are inside a CommandContext task.
            require $scriptPath;

            return 0;
        } catch (\Throwable $e) {
            echo "Execution failed: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
            return 1;
        }
    }

    public static function config(): CommandConfig
    {
        return new CommandConfig(
            description: 'Runs a Phalanx script with zero ceremony.',
            arguments: [
                Arg::required('script', 'The PHP script to run.'),
            ],
        );
    }
}
