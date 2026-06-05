<?php

declare(strict_types=1);

namespace Phalanx\Demos\Console\BasicCommands;

use JsonException;
use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Command\DescribesCommand;
use Phalanx\Console\Command\Opt;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Diagnostics\DeadlockReport;
use Phalanx\Task\Scopeable;

/**
 * Operator escape hatch. Snapshots every parked coroutine via DeadlockReport
 * and prints the canonical text format, or JSON when --json is set. Run this
 * command outside any live work to confirm it's a no-op (zero or one parked
 * coroutines for the dispatch itself); run it from a SIGUSR2 trap in a stuck
 * production process to dump the actual deadlock state.
 */
final class DebugDeadlockCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Snapshot every parked coroutine (operator escape hatch).',
            options: [Opt::flag('json', '', 'Emit JSON instead of formatted text.')],
        );
    }

    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);
        $report = DeadlockReport::collect();

        if ($ctx->options->flag('json')) {
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
