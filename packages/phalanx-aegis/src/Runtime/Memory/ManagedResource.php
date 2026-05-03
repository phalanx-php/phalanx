<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

final readonly class ManagedResource
{
    public function __construct(
        public string $id,
        public string $type,
        public ManagedResourceState $state,
        public int $generation,
        public ?string $parentResourceId,
        public ?string $ownerScopeId,
        public ?string $ownerRunId,
        public int $workerId,
        public int $coroutineId,
        public float $createdAt,
        public float $updatedAt,
        public ?float $terminalAt = null,
        public string $outcome = '',
        public string $reason = '',
        public bool $cancelRequested = false,
    ) {
    }
}
