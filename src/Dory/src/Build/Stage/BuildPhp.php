<?php

declare(strict_types=1);

namespace Phalanx\Dory\Build\Stage;

use Phalanx\Dory\Build\Pipeline\BuildStage;
use Phalanx\Dory\Build\Pipeline\StageResult;
use Phalanx\Dory\Build\Spc\SpcBuildContext;
use Phalanx\Dory\Build\Spc\SpcRunner;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class BuildPhp implements BuildStage
{
    public string $name = 'build-php';

    public string $description = 'Build static PHP binary';

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
