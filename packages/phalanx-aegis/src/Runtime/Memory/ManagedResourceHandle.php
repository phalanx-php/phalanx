<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

final readonly class ManagedResourceHandle
{
    public function __construct(
        public string $id,
        public string $type,
        public int $generation,
    ) {
    }
}
