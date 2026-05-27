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
    public string $name = 'stash-sources';

    public string $description = 'Download OpenSwoole sources';

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
            summary: 'OpenSwoole ' . $context->profile->openSwooleVersion . ' stashed',
        );
    }

    public function canSkip(SpcBuildContext $context): bool
    {
        $stashDir = $context->sourcePath . '/ext-openswoole-stash';
        return is_dir($stashDir) && is_file($stashDir . '/config.m4');
    }
}
