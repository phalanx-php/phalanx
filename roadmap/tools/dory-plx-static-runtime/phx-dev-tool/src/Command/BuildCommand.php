<?php

declare(strict_types=1);

namespace Phx\Command;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;
use Symfony\Component\Process\Process;

final class BuildCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);
        $output->persist("<info>Building Phalanx Static Binary...</info>");

        $projectRoot = getcwd();
        $appName = basename($projectRoot);
        $distDir = $projectRoot . '/dist';

        if (!is_dir($distDir)) {
            mkdir($distDir, 0755, true);
        }

        // 1. Bundle dependencies (rsync -aL)
        $output->persist("<comment>  - Bundling files...</comment>");
        $bundleDir = $projectRoot . '/.dory-bundle';
        if (is_dir($bundleDir)) {
            exec("rm -rf {$bundleDir}");
        }
        mkdir($bundleDir);

        $exclude = '--exclude="tests" --exclude="docs" --exclude=".git" --exclude="*.md" --exclude=".phpstan-cache"';
        exec("rsync -aL {$exclude} src/ {$bundleDir}/src/");
        exec("rsync -aL {$exclude} bin/ {$bundleDir}/bin/");
        exec("rsync -aL {$exclude} vendor/ {$bundleDir}/vendor/");

        // 2. Create PHAR
        $output->persist("<comment>  - Creating PHAR...</comment>");
        $pharPath = $projectRoot . '/app.phar';

        $compatPath = getenv('DORY_COMPAT_LAYER') ?: $projectRoot . '/compat.php';
        $compatContent = file_get_contents($compatPath);
        // Remove <?php tag
        $compatContent = str_replace(['<?php', '<?', '?>'], '', $compatContent);

        $stub = "<?php \n" . $compatContent . "\n namespace { require 'phar://' . __FILE__ . '/bin/app.php'; } __HALT_COMPILER();";

        $phar = new \Phar($pharPath);
        $phar->startBuffering();
        $phar->buildFromDirectory($bundleDir);
        $phar->setStub($stub);
        $phar->stopBuffering();

        // 3. Combine with runtime
        $output->persist("<comment>  - Linking runtime...</comment>");

        // Find current runtime
        $runtimePath = getenv('DORY_RUNTIME') ?: $_SERVER['_'] ?? getenv('HOME') . '/.dory/runtime/8.4.21-openswoole-26.2.0/php';

        // We need the micro SAPI (micro.sfx)
        $runtimeSfx = dirname($runtimePath) . '/micro.sfx';
        if (!file_exists($runtimeSfx)) {
            // Fallback to a local buildroot location for this PoC
            $runtimeSfx = $projectRoot . '/buildroot/bin/micro.sfx';
        }

        if (!file_exists($runtimeSfx)) {
            $output->persist("<error>Runtime micro.sfx not found.</error>");
            return 1;
        }

        $binaryPath = $distDir . '/' . $appName;
        exec("cat {$runtimeSfx} {$pharPath} > {$binaryPath}");
        chmod($binaryPath, 0755);

        unlink($pharPath);
        exec("rm -rf {$bundleDir}");

        $output->persist("<info>Build successful: {$binaryPath}</info>");

        return 0;
    }
}
