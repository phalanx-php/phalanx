<?php

declare(strict_types=1);

namespace Phalanx\Stream\Terminal;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Stream\Emitter;

final class Collect
{
    public function __construct(
        private readonly Emitter $source,
    ) {
    }

    /**
     * @return list<mixed>
     */
    public function __invoke(ExecutionScope $scope): array
    {
        $values = [];
        foreach (($this->source)($scope) as $value) {
            $scope->throwIfCancelled();
            $values[] = $value;
        }

        return $values;
    }
}
