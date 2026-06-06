<?php

declare(strict_types=1);

namespace Phalanx\Stream;

use Closure;
use Phalanx\Scope\ExecutionScope;

final class Stream
{
    private function __construct()
    {
    }

    public static function channel(int $bufferSize = 32): Channel
    {
        return new Channel($bufferSize);
    }

    /** @param Closure(ExecutionScope, Channel): void $producer */
    public static function produce(Closure $producer): Emitter
    {
        return Emitter::produce($producer);
    }

    public static function interval(float $seconds): Emitter
    {
        return Emitter::interval($seconds);
    }

    public static function from(ExecutionScope $scope, Emitter $emitter): Scoped
    {
        return Scoped::from($scope, $emitter);
    }
}
