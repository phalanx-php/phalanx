<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Response\Ignition;

use Phalanx\Supervisor\TaskTreeFormatter;
use Phalanx\Supervisor\TaskRunSnapshot;
use Spatie\Ignition\Contracts\Tab;

/**
 * Custom Ignition tab that renders the Phalanx Active Ledger.
 */
final class LedgerTab implements Tab
{
    /** @param list<TaskRunSnapshot> $snapshots */
    public function __construct(private readonly array $snapshots)
    {
    }

    public function name(): string
    {
        return 'Active Ledger';
    }

    public function component(): string
    {
        return 'custom-tab';
    }

    public function meta(): array
    {
        return [
            'title' => 'Phalanx Concurrency Snapshot',
            'label' => 'Ledger',
        ];
    }

    public function data(): array
    {
        if ($this->snapshots === []) {
            return ['Status' => 'No active tasks found in the supervisor.'];
        }

        try {
            $formatted = (new TaskTreeFormatter())->format($this->snapshots);
            return [
                'Fiber Hierarchy' => $formatted,
                'Total Tasks' => count($this->snapshots),
            ];
        } catch (\Throwable $e) {
            return [
                'Error' => 'Failed to format task tree.',
                'Message' => $e->getMessage(),
            ];
        }
    }
}
