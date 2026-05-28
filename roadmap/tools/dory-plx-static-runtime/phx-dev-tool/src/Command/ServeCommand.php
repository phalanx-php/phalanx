<?php

declare(strict_types=1);

namespace Phx\Command;

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
        $output->persist("<info>Starting Phalanx Dev Server...</info>");

        $entryPoint = (string) ($ctx->args->get('entry') ?? 'bin/app.php');

        if (!file_exists($entryPoint)) {
            $output->persist("<error>Entry point not found: {$entryPoint}</error>");
            return 1;
        }

        // Detect project root
        $projectRoot = getcwd();

        $processes = [
            Process::named('app')
                ->command("php {$entryPoint} example") // Run example command
                ->watch([$projectRoot . '/src', $projectRoot . '/bin'], ['php'])
        ];

        $server = new DevServer($processes);

        return $server->__invoke($ctx);
    }
}
