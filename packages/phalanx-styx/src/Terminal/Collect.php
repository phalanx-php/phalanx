<?php

declare(strict_types=1);

namespace Phalanx\Styx\Terminal;

use Phalanx\Scope\Stream\StreamContext;
use Phalanx\Styx\Emitter;

final class Collect
{
    public function __construct(
        private readonly Emitter $source,
    ) {}

    /**
     * @return list<mixed>
     */
    public function __invoke(StreamContext $context): array
    {
        $values = [];
        foreach (($this->source)($context) as $value) {
            $context->throwIfCancelled();
            $values[] = $value;
        }
        return $values;
    }
}
