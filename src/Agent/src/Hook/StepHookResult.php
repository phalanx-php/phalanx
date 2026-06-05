<?php

declare(strict_types=1);

namespace Phalanx\Agent\Hook;

use Phalanx\Agent\Turn\Outcome;

final class StepHookResult
{
    public function __construct(
        private(set) Outcome $outcome,
        private(set) ?\Throwable $error = null,
    ) {
    }

    public static function continue(): self
    {
        return new self(Outcome::Continue);
    }

    public static function stop(Outcome $outcome): self
    {
        if ($outcome === Outcome::Continue) {
            throw new \InvalidArgumentException('StepHookResult::stop() requires a terminal outcome.');
        }

        return new self($outcome);
    }

    public static function fail(\Throwable $error): self
    {
        return new self(Outcome::Failed, $error);
    }
}
