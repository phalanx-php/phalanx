<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Stage;

use Phalanx\DoryBin\Pipeline\BuildStage;
use Phalanx\DoryBin\Pipeline\StageResult;
use Phalanx\DoryBin\Spc\SpcBuildContext;
use Phalanx\DoryBin\Spc\SpcRunner;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class DownloadSources implements BuildStage
{
    public string $name = 'download';

    public string $description = 'Download PHP and extension sources';

    public function __invoke(TaskScope&TaskExecutor $scope, SpcBuildContext $context): StageResult
    {
        $start = hrtime(true);

        $runner = new SpcRunner($context);
        $result = $runner->download($scope);

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        if (!$result->success) {
            return new StageResult(
                stageName: $this->name,
                success: false,
                skipped: false,
                durationMs: $durationMs,
                summary: 'spc download failed: ' . trim($result->stderr),
            );
        }

        return new StageResult(
            stageName: $this->name,
            success: true,
            skipped: false,
            durationMs: $durationMs,
            summary: 'Sources downloaded',
        );
    }

    public function canSkip(SpcBuildContext $context): bool
    {
        return is_dir($context->sourcePath . '/php-src');
    }
}
