<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Stage;

use Phalanx\DoryBin\Pipeline\BuildStage;
use Phalanx\DoryBin\Pipeline\StageResult;
use Phalanx\DoryBin\Spc\SpcBuildContext;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class PreflightCheck implements BuildStage
{
    public string $name = 'preflight';

    public string $description = 'Check build prerequisites';

    public function __invoke(TaskScope&TaskExecutor $scope, SpcBuildContext $context): StageResult
    {
        $start = hrtime(true);
        $checks = [];
        $success = true;

        $phpOk = version_compare(PHP_VERSION, '8.4.0', '>=');
        $checks[] = $phpOk ? 'PHP ' . PHP_VERSION : 'PHP >= 8.4 required (got ' . PHP_VERSION . ')';

        if (!$phpOk) {
            $success = false;
        }

        $spcOk = is_executable($context->spcBinaryPath) || self::commandExists($context->spcBinaryPath);
        $checks[] = $spcOk ? 'spc binary found' : 'spc binary not found at ' . $context->spcBinaryPath;

        if (!$spcOk) {
            $success = false;
        }

        foreach (['autoconf', 'make', 'cmake', 'pkg-config', 'bison'] as $tool) {
            $exists = self::commandExists($tool);
            $checks[] = $exists ? "{$tool} available" : "{$tool} not found";

            if (!$exists) {
                $success = false;
            }
        }

        $freeBytes = disk_free_space($context->buildRoot);

        if ($freeBytes === false) {
            $freeBytes = disk_free_space(dirname($context->buildRoot));
        }

        $diskOk = $freeBytes !== false && $freeBytes > 2_000_000_000;
        $checks[] = $diskOk
            ? sprintf('Disk space: %.1f GB free', $freeBytes / 1_000_000_000)
            : 'Less than 2 GB disk space available';

        if (!$diskOk) {
            $success = false;
        }

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        return new StageResult(
            stageName: $this->name,
            success: $success,
            skipped: false,
            durationMs: $durationMs,
            summary: implode('; ', $checks),
        );
    }

    public function canSkip(SpcBuildContext $context): bool
    {
        return false;
    }

    private static function commandExists(string $command): bool
    {
        $result = shell_exec('which ' . escapeshellarg($command) . ' 2>/dev/null');
        return is_string($result) && trim($result) !== '';
    }
}
