<?php

declare(strict_types=1);

namespace Phalanx\Athena\Hook;

use Phalanx\Athena\Turn\Config;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Invocation;

final class StepContext
{
    private function __construct(
        private(set) Config $config,
        private(set) Log $log,
        private(set) ?Invocation $invocation,
        private(set) ?Cue $cue,
        private(set) Outcome $outcome,
    ) {
    }

    public static function beforeInvocation(Config $config, Log $log, Invocation $invocation): self
    {
        return new self($config, $log, $invocation, null, Outcome::Continue);
    }

    public static function afterCue(Config $config, Log $log, Invocation $invocation, Cue $cue): self
    {
        return new self($config, $log, $invocation, $cue, Outcome::Continue);
    }

    public static function afterInvocation(Config $config, Log $log, Invocation $invocation, Outcome $outcome): self
    {
        return new self($config, $log, $invocation, null, $outcome);
    }
}
