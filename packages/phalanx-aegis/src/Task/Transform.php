<?php

declare(strict_types=1);

namespace Phalanx\Task;

use Closure;
use Phalanx\Scope;

final readonly class Transform implements Scopeable
{
    public function __construct(
        private mixed $input,
        private Closure $transformer,
    ) {
    }

    public function __invoke(Scope $scope): mixed
    {
        return ($this->transformer)($this->input, $scope);
    }
}
