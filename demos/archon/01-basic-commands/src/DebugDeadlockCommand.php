<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\BasicCommands;

use JsonException;
use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Diagnostics\DeadlockReport;
use Phalanx\Task\Scopeable;

/**
 * Operator escape hatch. Snapshots every parked coroutine via DeadlockReport
 * and prints the canonical text format, or JSON when --json is set. Run this
 * command outside any live work to confirm it's a no-op (zero or one parked
 * coroutines for the dispatch itself); run it from a SIGUSR2 trap in a stuck
 * production process to dump the actual deadlock state.
 */
final class DebugDeadlockCommand implements Scopeable
{
    public function __invoke(CommandScope $scope): int
    {
        $output = $scope->service(StreamOutput::class);
        $report = DeadlockReport::collect();

        if ($scope->options->flag('json')) {
            $output->persist(self::renderJson($report));

            return 0;
        }

        $output->persist($report->format());

        return 0;
    }

    private static function renderJson(DeadlockReport $report): string
    {
        $payload = [
            'coroutineCount' => $report->coroutineCount,
            'collectedAt'    => $report->collectedAt,
            'frames'         => array_map(
                static fn($frame): array => [
                    'cid'       => $frame->cid,
                    'backtrace' => $frame->backtrace,
                ],
                $report->frames,
            ),
        ];

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (JsonException $e) {
            return '{"error":' . json_encode($e->getMessage()) . '}';
        }
    }
}
