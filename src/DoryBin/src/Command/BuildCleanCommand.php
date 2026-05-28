<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Command;

use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\DescribesCommand;
use Phalanx\Archon\Command\Opt;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\DoryBin\BuildConfig;
use Phalanx\DoryBin\Filesystem;
use Phalanx\Task\Scopeable;

final class BuildCleanCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Remove build artifacts',
            options: [
                Opt::flag('all', 'a', 'Remove all artifacts including downloads'),
            ],
        );
    }

    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);
        $config = $ctx->service(BuildConfig::class);
        $all = $ctx->options->flag('all');

        $buildRoot = $config->buildRoot;

        if (!is_dir($buildRoot)) {
            $output->persist("Build directory does not exist: {$buildRoot}");
            return 0;
        }

        if ($all) {
            Filesystem::removeDir($buildRoot);
            $output->persist("Removed: {$buildRoot}");
            return 0;
        }

        foreach (['buildroot', 'registry', 'bin'] as $subdir) {
            $path = $buildRoot . '/' . $subdir;
            if (is_dir($path)) {
                Filesystem::removeDir($path);
                $output->persist("Removed: {$path}");
            }
        }

        return 0;
    }
}
