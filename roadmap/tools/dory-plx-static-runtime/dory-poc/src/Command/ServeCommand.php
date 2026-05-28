<?php

declare(strict_types=1);

namespace Phx\Command;

use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Skopos\DevServer;
use Phalanx\Skopos\Process;
use Phalanx\Task\Scopeable;

final class ServeCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);
        $output->persist("<info>Starting Dory Dev Server...</info>");

        $entryPoint = (string) ($ctx->args->get('entry') ?? 'bin/app.php');

        if (!file_exists($entryPoint)) {
            $output->persist("<error>Entry point not found: {$entryPoint}</error>");
            return 1;
        }

        $projectRoot = getcwd();

        // Use the Dory static binary path if available, fallback to regular php
        $phpBin = getenv('DORY_RUNTIME') ?: $_SERVER['_'] ?? 'php';

        // Re-inject the compat layer so the spawned app has the same environment
        $compatPath = realpath(__DIR__ . '/../../compat.php');
        if ($compatPath) {
            $phpBin .= " -d auto_prepend_file={$compatPath}";
        }

        $processes = [
            Process::named('app')
                ->command("{$phpBin} {$entryPoint} example")
                ->watch([$projectRoot . '/src', $projectRoot . '/bin'], ['php'])
        ];

        $server = new DevServer($processes);

        return $server->__invoke($ctx);
    }

    public static function config(): CommandConfig
    {
        return new CommandConfig(
            description: 'Starts the development server with HMR.',
            arguments: [
                Arg::optional('entry', 'The PHP entrypoint script.', 'bin/app.php'),
            ],
        );
    }
}
