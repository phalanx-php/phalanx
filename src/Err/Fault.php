<?php

declare(strict_types=1);

namespace Phalanx\Err;

use Phalanx\Invocation\Attempt;
use Phalanx\Invocation\InvocationId;
use Throwable;

final class Fault
{
    /** @param non-empty-list<FaultLink> $chain */
    public function __construct(
        private(set) array $chain,
        private(set) InvocationId $invocationId,
        private(set) Attempt $attempt,
        private(set) ?string $operation = null,
    ) {
    }

    public static function fromThrowable(
        Throwable $throwable,
        InvocationId $invocationId,
        Attempt $attempt,
        ?string $operation = null,
    ): self {
        $chain = [];
        $current = $throwable;

        while ($current !== null) {
            $chain[] = FaultLink::fromThrowable($current);
            $current = $current->getPrevious();
        }

        return new self($chain, $invocationId, $attempt, $operation);
    }

    /** The thrown Throwable's own lineage (classic is-a check), variadic any-of. */
    public function isA(string ...$classes): bool
    {
        return $this->chain[0]->matches(...$classes);
    }

    /** Broad match: lineage walked across the entire causal chain. */
    public function is(string ...$classes): bool
    {
        foreach ($this->chain as $link) {
            if ($link->matches(...$classes)) {
                return true;
            }
        }

        return false;
    }
}
