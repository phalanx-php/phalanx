<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Stage;

use Phalanx\DoryBin\Pipeline\BuildStage;
use Phalanx\DoryBin\Pipeline\StageResult;
use Phalanx\DoryBin\Spc\SpcBuildContext;
use Phalanx\DoryBin\Spc\SpcRunner;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class BuildLibraries implements BuildStage
{
    public string $name = 'build-libs';

    public string $description = 'Build library dependencies';

    public function __invoke(TaskScope&TaskExecutor $scope, SpcBuildContext $context): StageResult
    {
        $start = hrtime(true);

        $runner = new SpcRunner($context);
        $result = $runner->buildLibs($scope);

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        if (!$result->success) {
            return new StageResult(
                stageName: $this->name,
                success: false,
                skipped: false,
                durationMs: $durationMs,
                summary: 'spc build libs failed: ' . trim($result->stderr),
            );
        }

        return new StageResult(
            stageName: $this->name,
            success: true,
            skipped: false,
            durationMs: $durationMs,
            summary: sprintf('Libraries built in %.1fs', $result->durationMs / 1000),
        );
    }

    public function canSkip(SpcBuildContext $context): bool
    {
        return is_dir($context->buildRoot . '/buildroot/lib');
    }
}
