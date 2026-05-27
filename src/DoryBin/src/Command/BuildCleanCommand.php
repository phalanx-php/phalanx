<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Command;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\DoryBin\BuildConfig;
use Phalanx\DoryBin\Filesystem;
use Phalanx\Task\Scopeable;

final class BuildCleanCommand implements Scopeable
{
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
