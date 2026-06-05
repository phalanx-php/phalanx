<?php

declare(strict_types=1);

namespace Phalanx\Agents\Effect;

use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Effect\Outcome as AiProvidersOutcome;
use Phalanx\Scope\TaskScope;

final class BuiltInExecutor implements Executor
{
    public function __invoke(TaskScope $scope, Requested $request, Context $context): Outcome
    {
        $scope->throwIfCancelled();

        $kind = BuiltInKind::from($request->effectId);

        return match ($kind) {
            BuiltInKind::Noop => Outcome::routed(
                Resolution::BuiltIn,
                AiProvidersOutcome::succeeded(null, 0),
            ),
            BuiltInKind::Echo => Outcome::routed(
                Resolution::BuiltIn,
                AiProvidersOutcome::succeeded(null, 0),
                $request->arguments,
            ),
            BuiltInKind::Halt => Outcome::halted(
                Resolution::BuiltIn,
                AiProvidersOutcome::succeeded(null, 0),
            ),
        };
    }
}
