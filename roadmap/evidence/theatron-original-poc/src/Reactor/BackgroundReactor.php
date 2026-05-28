<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactor;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Stream\TheatronStream;

interface BackgroundReactor
{
    public string $id { get; }

    public ?string $group { get; }

    public ReactorState $state { get; }

    public ReactorExclusivity $exclusivity { get; }

    public function start(ExecutionScope $scope, TheatronStream $stream): void;

    public function cancel(): void;
}
