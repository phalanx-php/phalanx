<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\Supervisor\TaskRunSnapshot;

final class StoaRequestDiagnostics
{
    /** @var list<TaskRunSnapshot> */
    private array $failureTree = [];

    /** @param list<TaskRunSnapshot> $snapshots */
    public function recordFailureTree(array $snapshots): void
    {
        $this->failureTree = $snapshots;
    }

    /** @return list<TaskRunSnapshot> */
    public function failureTree(): array
    {
        return $this->failureTree;
    }
}
