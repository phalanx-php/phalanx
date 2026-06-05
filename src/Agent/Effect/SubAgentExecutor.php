<?php

declare(strict_types=1);

namespace Phalanx\Agent\Effect;

use Phalanx\Cancellation\Cancelled;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Effect\Outcome as AiProvidersOutcome;
use Phalanx\Scope\TaskScope;

final class SubAgentExecutor implements Executor
{
    /** @param \Closure(TaskScope, Requested, Context): mixed $runner */
    public function __construct(private \Closure $runner)
    {
    }

    public function __invoke(TaskScope $scope, Requested $request, Context $context): Outcome
    {
        $scope->throwIfCancelled();

        $start = hrtime(true);

        try {
            $result = ($this->runner)($scope, $request, $context);
            $elapsed = (int) ((hrtime(true) - $start) / 1_000_000);

            return Outcome::routed(
                Resolution::SubAgent,
                AiProvidersOutcome::succeeded(null, $elapsed),
                $result,
            );
        } catch (Cancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            $elapsed = (int) ((hrtime(true) - $start) / 1_000_000);

            return Outcome::failed(
                Resolution::SubAgent,
                $e,
                AiProvidersOutcome::failed($e::class, $e->getMessage(), $elapsed),
            );
        }
    }
}
