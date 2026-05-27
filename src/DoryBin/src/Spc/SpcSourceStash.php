<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Spc;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\System\StreamingProcess;
use RuntimeException;

final class SpcSourceStash
{
    public function stash(TaskScope&TaskExecutor $scope, SpcBuildContext $context): void
    {
        $stashDir = $context->sourcePath . '/ext-openswoole-stash';
        $version = $context->profile->openSwooleVersion;

        if (is_dir($stashDir) && is_file($stashDir . '/config.m4')) {
            return;
        }

        if (!is_dir($context->sourcePath) && !mkdir($context->sourcePath, 0755, true) && !is_dir($context->sourcePath)) {
            throw new RuntimeException("Failed to create source directory: {$context->sourcePath}");
        }

        $tarballUrl = "https://github.com/openswoole/ext-openswoole/archive/refs/tags/v{$version}.tar.gz";
        $tarballPath = $context->buildRoot . "/openswoole-{$version}.tar.gz";

        $this->downloadFile($scope, $tarballUrl, $tarballPath, $context);

        if (!is_dir($stashDir) && !mkdir($stashDir, 0755, true) && !is_dir($stashDir)) {
            throw new RuntimeException("Failed to create stash directory: {$stashDir}");
        }

        $this->extract($scope, $tarballPath, $stashDir, $context);
    }

    private function downloadFile(
        TaskScope&TaskExecutor $scope,
        string $url,
        string $dest,
        SpcBuildContext $context,
    ): void {
        $argv = ['curl', '-fSL', '-o', $dest, $url];

        $handle = StreamingProcess::command($argv)
            ->withCwd($context->buildRoot)
            ->withEnv($context->environment)
            ->start($scope);

        try {
            $exitCode = $handle->wait();

            if ($exitCode !== 0) {
                $stderr = $handle->getIncrementalErrorOutput();
                $handle->close('curl.failed');
                throw new RuntimeException("curl failed (exit {$exitCode}): {$stderr}");
            }

            $handle->close('curl.completed');
        } catch (Cancelled $e) {
            $handle->kill();
            throw $e;
        } catch (\Throwable $e) {
            $handle->kill();
            throw $e;
        }
    }

    private function extract(
        TaskScope&TaskExecutor $scope,
        string $tarball,
        string $targetDir,
        SpcBuildContext $context,
    ): void {
        $argv = ['tar', 'xzf', $tarball, '-C', $targetDir, '--strip-components=1'];

        $handle = StreamingProcess::command($argv)
            ->withCwd($context->buildRoot)
            ->withEnv($context->environment)
            ->start($scope);

        try {
            $exitCode = $handle->wait();

            if ($exitCode !== 0) {
                $stderr = $handle->getIncrementalErrorOutput();
                $handle->close('tar.failed');
                throw new RuntimeException("tar failed (exit {$exitCode}): {$stderr}");
            }

            $handle->close('tar.completed');
        } catch (Cancelled $e) {
            $handle->kill();
            throw $e;
        } catch (\Throwable $e) {
            $handle->kill();
            throw $e;
        }
    }
}
