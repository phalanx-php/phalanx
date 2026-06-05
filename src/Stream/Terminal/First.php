<?php

declare(strict_types=1);

namespace Phalanx\Stream\Terminal;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Stream\Emitter;
use RuntimeException;

final class First
{
    public function __construct(
        private readonly Emitter $source,
    ) {
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        foreach (($this->source)($scope) as $value) {
            $scope->throwIfCancelled();

            return $value;
        }

        throw new RuntimeException('Stream completed without emitting any values.');
    }
}
