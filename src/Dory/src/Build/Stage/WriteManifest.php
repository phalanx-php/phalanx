<?php

declare(strict_types=1);

namespace Phalanx\Dory\Build\Stage;

use Phalanx\Dory\Build\BuildManifestWriter;
use Phalanx\Dory\Build\Pipeline\BuildStage;
use Phalanx\Dory\Build\Pipeline\StageResult;
use Phalanx\Dory\Build\Spc\SpcBuildContext;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class WriteManifest implements BuildStage
{
    public string $name = 'write-manifest';

    public string $description = 'Generate build manifest';

    public function __invoke(TaskScope&TaskExecutor $scope, SpcBuildContext $context): StageResult
    {
        $start = hrtime(true);
        $binaryPath = $context->outputPath;
        $manifestPath = $context->buildRoot . '/dory-build.lock.json';

        $manifest = BuildManifestWriter::fromContext($context, $binaryPath);
        BuildManifestWriter::write($manifest, $manifestPath);

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        return new StageResult(
            stageName: $this->name,
            success: true,
            skipped: false,
            durationMs: $durationMs,
            summary: 'Manifest written to ' . $manifestPath,
        );
    }

    public function canSkip(SpcBuildContext $context): bool
    {
        return false;
    }
}
