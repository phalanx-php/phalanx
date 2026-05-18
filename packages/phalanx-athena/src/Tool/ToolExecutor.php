<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tool;

use Phalanx\Athena\Effect\Context;
use Phalanx\Athena\Effect\Executor;
use Phalanx\Athena\Effect\Outcome;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Effect\Outcome as PanoplyOutcome;
use Phalanx\Scope\TaskScope;

final class ToolExecutor implements Executor
{
    public function __construct(
        private(set) ToolRegistry $registry,
    ) {
    }

    public function __invoke(TaskScope $scope, Requested $request, Context $context): Outcome
    {
        $start = hrtime(true);

        try {
            $result = $this->registry->invoke($scope, $request->effectId, $context, $request->arguments);
            $elapsed = (int) ((hrtime(true) - $start) / 1_000_000);

            return Outcome::routed(Resolution::LocalTool, PanoplyOutcome::succeeded(null, $elapsed), $result->data);
        } catch (Cancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            $elapsed = (int) ((hrtime(true) - $start) / 1_000_000);

            return Outcome::failed(
                Resolution::LocalTool,
                $e,
                PanoplyOutcome::failed($e::class, $e->getMessage(), $elapsed),
            );
        }
    }
}
