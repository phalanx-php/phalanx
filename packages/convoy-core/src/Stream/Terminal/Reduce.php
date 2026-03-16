<?php

declare(strict_types=1);

namespace Convoy\Stream\Terminal;

use Convoy\Stream\Contract\StreamContext;
use Convoy\Stream\Contract\StreamSource;

final readonly class Reduce
{
    public function __construct(
        private StreamSource $source,
        /** @var callable(mixed, mixed, int|string, StreamContext): mixed */
        private mixed $reducer,
        private mixed $initial = null,
    ) {
    }

    public function __invoke(StreamContext $ctx): mixed
    {
        $accumulator = $this->initial;

        foreach (($this->source)($ctx) as $key => $value) {
            $ctx->throwIfCancelled();
            $accumulator = ($this->reducer)($accumulator, $value, $key, $ctx);
        }

        return $accumulator;
    }
}
