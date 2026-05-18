<?php

declare(strict_types=1);

namespace Phalanx\Athena\Effect;

use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Effect\Outcome as PanoplyOutcome;
use Phalanx\Scope\TaskScope;

final class BuiltInExecutor implements Executor
{
    public function __invoke(TaskScope $scope, Requested $request, Context $context): Outcome
    {
        $kind = BuiltInKind::from($request->effectId);

        return match ($kind) {
            BuiltInKind::Noop => Outcome::routed(
                Resolution::BuiltIn,
                PanoplyOutcome::succeeded(null, 0),
            ),
            BuiltInKind::Echo => Outcome::routed(
                Resolution::BuiltIn,
                PanoplyOutcome::succeeded(null, 0),
                $request->arguments,
            ),
            BuiltInKind::Halt => Outcome::halted(
                Resolution::BuiltIn,
                PanoplyOutcome::succeeded(null, 0),
            ),
        };
    }
}
