<?php

declare(strict_types=1);

namespace Phalanx\Archon\Demo;

use JsonException;
use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Diagnostics\DeadlockReport;
use Phalanx\Task\Scopeable;

/**
 * Operator escape hatch for diagnosing a stuck Phalanx process.
 *
 * Snapshots every parked coroutine's backtrace via DeadlockReport::collect
 * and writes it to the command's StreamOutput. Outside an OpenSwoole
 * coroutine context the report is empty (no live coroutines to inspect)
 * and the command exits 0 — that is the expected pre-server behaviour.
 *
 * --json emits a machine-readable payload built from the report's public
 * properties; the default text path uses DeadlockReport::format().
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
