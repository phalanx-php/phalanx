<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Stage;

use Phalanx\DoryBin\Pipeline\BuildStage;
use Phalanx\DoryBin\Pipeline\StageResult;
use Phalanx\DoryBin\Spc\SpcBuildContext;
use Phalanx\DoryBin\Spc\SpcRunner;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class BuildPhp implements BuildStage
{
    private(set) string $name = 'build-php';

    private(set) string $description = 'Build static PHP binary';

    public function __invoke(TaskScope&TaskExecutor $scope, SpcBuildContext $context): StageResult
    {
        $start = hrtime(true);

        $runner = new SpcRunner($context);
        $result = $runner->buildPhp($scope);

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        if (!$result->success) {
            return new StageResult(
                stageName: $this->name,
                success: false,
                skipped: false,
                durationMs: $durationMs,
                summary: 'spc build php-cli failed: ' . trim($result->stderr),
            );
        }

        return new StageResult(
            stageName: $this->name,
            success: true,
            skipped: false,
            durationMs: $durationMs,
            summary: 'Static PHP binary built',
        );
    }

    public function canSkip(SpcBuildContext $context): bool
    {
        return false;
    }
}
