<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

final readonly class RuntimeLifecycleEvent
{
    public function __construct(
        public int $sequence,
        public string $type,
        public string $resourceId,
        public string $scopeId,
        public string $runId,
        public string $state,
        public float $occurredAt,
        public string $valueA = '',
        public string $valueB = '',
    ) {
    }
}
