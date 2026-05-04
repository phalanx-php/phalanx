<?php

declare(strict_types=1);

namespace Phalanx\Scope\Stream;

use Generator;

/**
 * A pull-based stream of values produced under a managed StreamContext.
 *
 * Implementations yield values via Generator and honor scope cancellation
 * through $context->throwIfCancelled(). Producer-side cleanup must register
 * with $context->onDispose() so the scope tears the source down whether
 * the stream completes naturally, errors, or is cancelled.
 *
 * @template TValue
 */
interface StreamSource
{
    /**
     * @return Generator<int, TValue>
     */
    public function __invoke(StreamContext $context): Generator;
}
