<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\Supervisor\TaskRunSnapshot;

final class HttpRequestDiagnostics
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
