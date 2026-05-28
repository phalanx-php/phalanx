<?php

declare(strict_types=1);

namespace Phalanx\Dory\Command;

use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\DescribesCommand;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Dory\DoryBuilder;
use Phalanx\Skopos\FileWatcher;
use Phalanx\Task\Executable;

final class ServeCommand implements Executable, DescribesCommand
{
    private const int PARK_UNTIL_CANCELLED = PHP_INT_MAX;

    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Run and watch a Dory script for changes',
            arguments: [
                Arg::required('script', 'Path to the Dory script'),
            ],
        );
    }

    public function __invoke(CommandContext $ctx): int
    {
        $scriptPath = (string) $ctx->args->required('script');
        $resolved = realpath($scriptPath);

        if ($resolved === false || !file_exists($resolved)) {
            fwrite(STDERR, "Script not found: {$scriptPath}\n");
            return 1;
        }

        $output = $ctx->service(StreamOutput::class);
        $directory = dirname($resolved);

        $output->persist("Watching {$directory} for changes. Press Ctrl+C to stop.");

        self::runScript($resolved, $output);

        $watcher = new FileWatcher(
            paths: [$directory],
            extensions: ['php'],
            onChange: static function (array $changed) use ($resolved, $output): void {
                $output->persist('');
                $output->persist('Changed: ' . implode(', ', $changed));
                self::runScript($resolved, $output);
            },
        );

        $watcher->start($ctx);

        $ctx->onDispose(static function () use ($watcher): void {
            $watcher->stop();
        });

        $ctx->delay(self::PARK_UNTIL_CANCELLED);

        return 0;
    }

    private static function runScript(string $resolved, StreamOutput $output): void
    {
        $output->persist('');
        $output->persist('Running ' . basename($resolved) . ' ...');

        $builder = new DoryBuilder();
        $builder->script($resolved);
        $code = $builder->run();

        if ($code !== 0) {
            $output->persist("Exited with code {$code}.");
        }
    }
}
