<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\RuntimeLifecycle;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use RuntimeException;

/**
 * Long-running command exercising every interrupt path the runtime owns:
 *
 *   - open a ManagedCounter resource
 *   - register $ctx->onDispose so the close() banner fires even on abort
 *   - spawn three ticker workers via $ctx->go (TaskRun supervision)
 *   - park the main coroutine on $ctx->delay() checkpoints — Cancelled
 *     reaches the body when the scope token is cancelled (signal trap,
 *     cooperative timeout, manual)
 *
 * Flags:
 *   --fail-worker=N    inject a RuntimeException inside ticker N
 *   --duration=SEC     bound the inner loop (default 30s — long enough for
 *                      external cancel signals to arrive)
 */
final class WatchCommand implements Executable
{
    public function __invoke(CommandContext $ctx): int
    {
        $output     = $ctx->service(StreamOutput::class);
        $duration   = (float) $ctx->options->get('duration', 30.0);
        $failWorker = (int) $ctx->options->get('fail-worker', 0);

        $resource = new ManagedCounter($output);
        $ctx->onDispose(static function () use ($resource): void {
            $resource->close();
        });

        $output->persist('ready');

        for ($id = 1; $id <= 3; $id++) {
            $shouldFail = $id === $failWorker;
            $ctx->go(static function (ExecutionScope $workerScope) use ($output, $id, $shouldFail): void {
                for ($n = 1; $n <= 100; $n++) {
                    if ($workerScope->isCancelled) {
                        return;
                    }
                    $output->persist("[tick {$id} {$n}]");
                    if ($shouldFail && $n === 2) {
                        throw new RuntimeException("worker {$id} crashed");
                    }
                    $workerScope->delay(0.05);
                }
            }, name: "tick-{$id}");
        }

        $deadline = microtime(true) + $duration;
        $exit     = 0;

        try {
            while (microtime(true) < $deadline) {
                $ctx->delay(0.05);
                $ctx->throwIfCancelled();
            }
            $output->persist('[completed normally]');
        } catch (Cancelled $e) {
            $output->persist('[cancelled: ' . $e->getMessage() . ']');
            $exit = 130;
        }

        return $exit;
    }
}
