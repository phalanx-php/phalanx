<?php

declare(strict_types=1);

namespace Convoy\Task;

use Convoy\Concurrency\RetryPolicy;
use UnitEnum;

final class TaskConfig
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public private(set) string $name = '',
        public private(set) int $priority = 0,
        public private(set) ?UnitEnum $pool = null,
        public private(set) ?RetryPolicy $retry = null,
        public private(set) ?float $timeout = null,
        public private(set) ?int $concurrencyLimit = null,
        public private(set) bool $trace = true,
        public private(set) array $tags = [],
    ) {
    }

    /**
     * @param list<string>|null $tags
     */
    public function with(
        ?string $name = null,
        ?int $priority = null,
        ?UnitEnum $pool = null,
        ?RetryPolicy $retry = null,
        ?float $timeout = null,
        ?int $concurrencyLimit = null,
        ?bool $trace = null,
        ?array $tags = null,
    ): self {
        $clone = clone $this;
        if ($name !== null) {
            $clone->name = $name;
        }
        if ($priority !== null) {
            $clone->priority = $priority;
        }
        if ($pool !== null) {
            $clone->pool = $pool;
        }
        if ($retry !== null) {
            $clone->retry = $retry;
        }
        if ($timeout !== null) {
            $clone->timeout = $timeout;
        }
        if ($concurrencyLimit !== null) {
            $clone->concurrencyLimit = $concurrencyLimit;
        }
        if ($trace !== null) {
            $clone->trace = $trace;
        }
        if ($tags !== null) {
            $clone->tags = $tags;
        }
        return $clone;
    }
}
