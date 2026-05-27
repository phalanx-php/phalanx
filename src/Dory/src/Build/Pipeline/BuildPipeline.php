<?php

declare(strict_types=1);

namespace Phalanx\Dory\Build\Pipeline;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Dory\Build\Spc\SpcBuildContext;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

class BuildPipeline
{
    /** @var list<BuildStage> */
    private array $stages = [];

    public function add(BuildStage $stage): void
    {
        $this->stages[] = $stage;
    }

    /** @return list<StageResult> */
    public function execute(
        TaskScope&TaskExecutor $scope,
        SpcBuildContext $context,
        ?BuildProgress $progress = null,
    ): array {
        $results = [];

        foreach ($this->stages as $stage) {
            if ($stage->canSkip($context)) {
                $result = new StageResult($stage->name, true, true, 0.0, 'Skipped (cached)');
                $progress?->stageCompleted($result);
                $results[] = $result;
                continue;
            }

            $start = hrtime(true);
            $progress?->stageStarted($stage->name);

            try {
                $result = ($stage)($scope, $context);
                $progress?->stageCompleted($result);
                $results[] = $result;

                if (!$result->success) {
                    break;
                }
            } catch (Cancelled $e) {
                throw $e;
            } catch (\Throwable $e) {
                $durationMs = (hrtime(true) - $start) / 1_000_000;
                $result = new StageResult($stage->name, false, false, $durationMs, $e->getMessage());
                $progress?->stageCompleted($result);
                $results[] = $result;
                break;
            }
        }

        return $results;
    }
}
