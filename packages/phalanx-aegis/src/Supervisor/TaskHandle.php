<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

use Closure;

final class TaskHandle
{
    /**
     * @param Closure(): void $cancel
     * @param Closure(): ?TaskRunSnapshot $snapshot
     */
    public function __construct(
        private(set) string $id,
        private(set) string $name,
        private Closure $cancel,
        private Closure $snapshot,
    ) {
    }

    public function cancel(): void
    {
        ($this->cancel)();
    }

    public function snapshot(): ?TaskRunSnapshot
    {
        return ($this->snapshot)();
    }
}
