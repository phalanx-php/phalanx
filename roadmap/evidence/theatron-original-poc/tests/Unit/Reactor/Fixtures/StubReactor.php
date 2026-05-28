<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Reactor\Fixtures;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Reactor\BackgroundReactor;
use Phalanx\Theatron\Reactor\ReactorExclusivity;
use Phalanx\Theatron\Reactor\ReactorState;
use Phalanx\Theatron\Stream\TheatronStream;

final class StubReactor implements BackgroundReactor
{
    public ReactorState $state { get => $this->currentState; }
    public ReactorExclusivity $exclusivity { get => $this->exclusivityMode; }

    public bool $wasCancelled = false;
    private ReactorState $currentState = ReactorState::Idle;

    public function __construct(
        private(set) string $id,
        private(set) ?string $group = null,
        private ReactorExclusivity $exclusivityMode = ReactorExclusivity::Exclusive,
    ) {
    }

    public function start(ExecutionScope $scope, TheatronStream $stream): void
    {
        $this->currentState = ReactorState::Running;
    }

    public function cancel(): void
    {
        $this->currentState = ReactorState::Cancelled;
        $this->wasCancelled = true;
    }
}
