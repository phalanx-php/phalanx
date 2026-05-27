<?php

declare(strict_types=1);

namespace Phalanx\Dory\Build\Stage;

use Phalanx\Dory\Build\Pipeline\BuildStage;
use Phalanx\Dory\Build\Pipeline\StageResult;
use Phalanx\Dory\Build\Spc\SpcBuildContext;
use Phalanx\Dory\Build\Verify\BinarySizeCheck;
use Phalanx\Dory\Build\Verify\ExtensionCheck;
use Phalanx\Dory\Build\Verify\FiberContextCheck;
use Phalanx\Dory\Build\Verify\SmokeTestCheck;
use Phalanx\Dory\Build\Verify\SymbolConflictCheck;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class VerifyBinary implements BuildStage
{
    public string $name = 'verify';

    public string $description = 'Verify built binary';

    public function __invoke(TaskScope&TaskExecutor $scope, SpcBuildContext $context): StageResult
    {
        $binaryPath = $context->outputPath;

        if (!is_file($binaryPath)) {
            return new StageResult(
                stageName: $this->name,
                success: false,
                skipped: false,
                durationMs: 0.0,
                summary: 'Binary not found at ' . $binaryPath,
            );
        }

        $checks = [
            new ExtensionCheck(),
            new FiberContextCheck(),
            new SmokeTestCheck(),
            new SymbolConflictCheck(),
            new BinarySizeCheck(),
        ];

        $start = hrtime(true);
        $failures = [];

        foreach ($checks as $check) {
            $result = $check->check($scope, $binaryPath, $context->profile);
            if (!$result->passed) {
                $failures[] = "{$result->checkName}: {$result->message}";
            }
        }

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        if ($failures !== []) {
            return new StageResult(
                stageName: $this->name,
                success: false,
                skipped: false,
                durationMs: $durationMs,
                summary: implode('; ', $failures),
            );
        }

        return new StageResult(
            stageName: $this->name,
            success: true,
            skipped: false,
            durationMs: $durationMs,
            summary: 'All checks passed',
        );
    }

    public function canSkip(SpcBuildContext $context): bool
    {
        return false;
    }
}
