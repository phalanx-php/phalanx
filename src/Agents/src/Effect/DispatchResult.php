<?php

declare(strict_types=1);

namespace Phalanx\Agents\Effect;

use Phalanx\Agents\Turn;

final class DispatchResult
{
    private function __construct(
        private(set) Turn\Outcome $turnOutcome,
        private(set) ?Outcome $effectOutcome = null,
        private(set) mixed $data = null,
        private(set) ?\Throwable $error = null,
    ) {
    }

    public static function continue(Outcome $outcome, mixed $data = null): self
    {
        return new self(Turn\Outcome::Continue, $outcome, $data);
    }

    public static function denied(Outcome $outcome, \Throwable $error): self
    {
        return new self(Turn\Outcome::Failed, $outcome, error: $error);
    }

    public static function paused(Outcome $outcome): self
    {
        return new self(Turn\Outcome::WaitingForApproval, $outcome);
    }

    public static function failed(Outcome $outcome, \Throwable $error): self
    {
        return new self(Turn\Outcome::Failed, $outcome, error: $error);
    }

    public static function halted(Outcome $outcome): self
    {
        return new self(Turn\Outcome::Complete, $outcome);
    }
}
