<?php

declare(strict_types=1);

namespace AgentBridge\Lego;

use AgentBridge\Tab\TabScope;

/**
 * Executes a LegoDefinition against a TabScope and persists the outcome.
 *
 * Each execution produces a new LegoDefinition instance (copy-on-write) with
 * updated counters. The caller's reference to $lego becomes stale after execute()
 * returns -- the library now holds the authoritative version.
 */
final class LegoExecutor
{
    public function __construct(
        private readonly TabScope $tab,
    ) {}

    /**
     * Runs the lego's steps, records the outcome, and saves back to the library.
     *
     * Returns true on success, false on any exception. The failure is persisted
     * immediately so the confidence score degrades even if the caller discards the error.
     */
    public function execute(LegoDefinition $lego, LegoLibrary $library): bool
    {
        try {
            $this->tab->executeAction($lego->steps);
            $library->save($lego->withExecution(succeeded: true));
            return true;
        } catch (\Throwable) {
            $library->save($lego->withExecution(succeeded: false));
            return false;
        }
    }

    /**
     * Runs each lego in series and returns the count of successful executions.
     *
     * Failures do not short-circuit the batch -- each lego is attempted regardless
     * of prior failures. This matches the intent that legos are independent sequences.
     *
     * @param list<LegoDefinition> $legos
     */
    public function executeBatch(array $legos, LegoLibrary $library): int
    {
        $succeeded = 0;

        foreach ($legos as $lego) {
            if ($this->execute($lego, $library)) {
                $succeeded++;
            }
        }

        return $succeeded;
    }
}
