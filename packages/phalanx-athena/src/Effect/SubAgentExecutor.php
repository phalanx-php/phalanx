<?php

declare(strict_types=1);

namespace Phalanx\Athena\Effect;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Effect\Outcome as PanoplyOutcome;
use Phalanx\Scope\TaskScope;

final class SubAgentExecutor implements Executor
{
    /** @var \Closure(TaskScope, Requested, Context): mixed */
    private(set) \Closure $runner;

    /** @param \Closure(TaskScope, Requested, Context): mixed $runner */
    public function __construct(\Closure $runner)
    {
        $this->runner = $runner;
    }

    public function __invoke(TaskScope $scope, Requested $request, Context $context): Outcome
    {
        $scope->throwIfCancelled();

        $start = hrtime(true);

        try {
            $result  = ($this->runner)($scope, $request, $context);
            $elapsed = (int) ((hrtime(true) - $start) / 1_000_000);

            return Outcome::routed(
                Resolution::SubAgent,
                PanoplyOutcome::succeeded(null, $elapsed),
                $result,
            );
        } catch (Cancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            $elapsed = (int) ((hrtime(true) - $start) / 1_000_000);

            return Outcome::failed(
                Resolution::SubAgent,
                $e,
                PanoplyOutcome::failed($e::class, $e->getMessage(), $elapsed),
            );
        }
    }
}
