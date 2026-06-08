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

    public static function captureBuffer(): ResourceHandle
    {
        return ResourceHandle::captureBuffer();
    }

    public static function memoryBuffer(string $contents = ''): ResourceHandle
    {
        return ResourceHandle::memoryBuffer($contents);
    }

    public static function memoryInput(string $contents = ''): ResourceHandle
    {
        return ResourceHandle::memoryInput($contents);
    }

    public static function nullInput(): ResourceHandle
    {
        return ResourceHandle::nullInput();
    }
}
