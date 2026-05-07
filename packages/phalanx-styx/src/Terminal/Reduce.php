<?php

declare(strict_types=1);

namespace Phalanx\Styx\Terminal;

use Closure;
use Phalanx\Scope\Stream\StreamContext;
use Phalanx\Styx\Emitter;

final class Reduce
{
    /**
     * @param Closure(mixed, mixed, int): mixed $reducer
     */
    public function __construct(
        private readonly Emitter $source,
        private readonly Closure $reducer,
        private readonly mixed $initial,
    ) {}

    public function __invoke(StreamContext $context): mixed
    {
        $accumulator = $this->initial;
        foreach (($this->source)($context) as $key => $value) {
            $context->throwIfCancelled();
            $accumulator = ($this->reducer)($accumulator, $value, $key);
        }
        return $accumulator;
    }
}
