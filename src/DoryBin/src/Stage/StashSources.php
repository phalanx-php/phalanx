<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Stage;

use Phalanx\DoryBin\Pipeline\BuildStage;
use Phalanx\DoryBin\Pipeline\StageResult;
use Phalanx\DoryBin\Spc\SpcBuildContext;
use Phalanx\DoryBin\Spc\SpcSourceStash;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class StashSources implements BuildStage
{
    private(set) string $name = 'stash-sources';

    private(set) string $description = 'Download Swoole sources';

    public function __invoke(TaskScope&TaskExecutor $scope, SpcBuildContext $context): StageResult
    {
        $start = hrtime(true);

        $stash = new SpcSourceStash();
        $stash->stash($scope, $context);

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        return new StageResult(
            stageName: $this->name,
            success: true,
            skipped: false,
            durationMs: $durationMs,
            summary: 'Swoole ' . $context->profile->swooleVersion . ' stashed',
        );
    }

    public function canSkip(SpcBuildContext $context): bool
    {
        $stashDir = $context->sourcePath . '/ext-swoole-stash';
        return is_dir($stashDir) && is_file($stashDir . '/config.m4');
    }
}
