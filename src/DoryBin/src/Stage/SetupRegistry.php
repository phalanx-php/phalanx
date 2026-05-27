<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Stage;

use Phalanx\DoryBin\Pipeline\BuildStage;
use Phalanx\DoryBin\Pipeline\StageResult;
use Phalanx\DoryBin\Spc\SpcBuildContext;
use Phalanx\DoryBin\Spc\SpcRegistryGenerator;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class SetupRegistry implements BuildStage
{
    public string $name = 'setup-registry';

    public string $description = 'Generate spc build registry';

    public function __invoke(TaskScope&TaskExecutor $scope, SpcBuildContext $context): StageResult
    {
        $start = hrtime(true);

        $generator = new SpcRegistryGenerator();
        $generator->generate($context);

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        return new StageResult(
            stageName: $this->name,
            success: true,
            skipped: false,
            durationMs: $durationMs,
            summary: 'Registry generated at ' . $context->registryPath,
        );
    }

    public function canSkip(SpcBuildContext $context): bool
    {
        return is_file($context->registryPath . '/spc.registry.yml');
    }
}
