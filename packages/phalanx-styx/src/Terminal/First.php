<?php

declare(strict_types=1);

namespace Phalanx\Styx\Terminal;

use Phalanx\Scope\Stream\StreamContext;
use Phalanx\Styx\Emitter;
use RuntimeException;

final class First
{
    public function __construct(
        private readonly Emitter $source,
    ) {}

    public function __invoke(StreamContext $context): mixed
    {
        foreach (($this->source)($context) as $value) {
            $context->throwIfCancelled();
            return $value;
        }
        throw new RuntimeException('Stream completed without emitting any values.');
    }
}
