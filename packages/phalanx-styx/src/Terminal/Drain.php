<?php

declare(strict_types=1);

namespace Phalanx\Styx\Terminal;

use Phalanx\Scope\Stream\StreamContext;
use Phalanx\Styx\Emitter;

final class Drain
{
    public function __construct(
        private readonly Emitter $source,
    ) {}

    public function __invoke(StreamContext $context): void
    {
        foreach (($this->source)($context) as $_) {
            $context->throwIfCancelled();
        }
    }
}
