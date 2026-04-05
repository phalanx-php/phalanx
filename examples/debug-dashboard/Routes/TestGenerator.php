<?php

declare(strict_types=1);

use Phalanx\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\WebSocket\WsGateway;
use Phalanx\WebSocket\WsMessage;
use React\Http\Message\Response;

final class TestGenerator
{
    public function __invoke(ExecutionScope $scope): Response
    {
        $body = json_decode((string) $scope->attribute('request')->getBody(), true);

        if (!is_array($body)) {
            return Response::json(['error' => 'Invalid JSON'])->withStatus(400);
        }

        $count = max(1, min(500, (int) ($body['count'] ?? 20)));
        $mode = $body['mode'] ?? 'sequential';
        $channel = $body['channel'] ?? 'test';

        $store = $scope->service(DumpStore::class);
        $gateway = $scope->service(WsGateway::class);

        $publish = static function (array $data, string $ch) use ($store, $gateway): array {
            $entry = $store->push([
                'channel' => $ch,
                'data' => $data,
                'file' => 'TestGenerator.php',
                'line' => null,
            ]);

            $msg = WsMessage::json(['type' => 'dump', 'entry' => $entry]);
            $gateway->publish("dump:{$ch}", $msg);
            $gateway->publish('dump:*', $msg);

            return $entry;
        };

        $started = hrtime(true);

        $result = match ($mode) {
            'sequential' => self::sequential($scope, $count, $channel, $publish),
            'concurrent' => self::concurrent($scope, $count, $channel, $publish),
            'burst' => self::burst($scope, $count, $channel, $publish),
            'waterfall' => self::waterfall($scope, $count, $channel, $publish),
            'settle' => self::settle($scope, $count, $channel, $publish),
            default => ['error' => "Unknown mode: {$mode}"],
        };

        $elapsed = (hrtime(true) - $started) / 1e6;

        return Response::json([
            'ok' => true,
            'mode' => $mode,
            'count' => $count,
            'elapsed_ms' => round($elapsed, 2),
            ...$result,
        ]);
    }

    private static function sequential(
        ExecutionScope $scope,
        int $count,
        string $channel,
        \Closure $publish,
    ): array {
        $ids = [];

        $tasks = [];
        for ($i = 1; $i <= $count; $i++) {
            $n = $i;
            $tasks[] = Task::of(static function () use ($n, $count, $channel, $publish, &$ids): void {
                $entry = $publish([
                    'mode' => 'sequential',
                    'index' => $n,
                    'total' => $count,
                    'message' => "Event {$n}/{$count} (one at a time)",
                ], $channel);
                $ids[] = $entry['id'];
            });
        }

        $scope->series($tasks);

        return ['ids' => $ids];
    }

    private static function concurrent(
        ExecutionScope $scope,
        int $count,
        string $channel,
        \Closure $publish,
    ): array {
        $ids = [];
        $limit = min($count, 5);

        $scope->map(
            range(1, $count),
            static fn(int $n) => Task::of(static function (ExecutionScope $es) use ($n, $count, $channel, $publish, &$ids, $limit): void {
                $es->delay(0.01 * random_int(1, 10));
                $entry = $publish([
                    'mode' => 'concurrent',
                    'index' => $n,
                    'total' => $count,
                    'concurrency_limit' => $limit,
                    'fiber' => spl_object_id(\Fiber::getCurrent()),
                    'message' => "Fiber-interleaved {$n}/{$count} (limit {$limit})",
                ], $channel);
                $ids[] = $entry['id'];
            }),
            limit: $limit,
        );

        return ['ids' => $ids, 'concurrency_limit' => $limit];
    }

    private static function burst(
        ExecutionScope $scope,
        int $count,
        string $channel,
        \Closure $publish,
    ): array {
        $ids = [];

        $tasks = [];
        for ($i = 1; $i <= $count; $i++) {
            $n = $i;
            $tasks["burst-{$n}"] = Task::of(static function (ExecutionScope $es) use ($n, $count, $channel, $publish, &$ids): void {
                $entry = $publish([
                    'mode' => 'burst',
                    'index' => $n,
                    'total' => $count,
                    'fiber' => spl_object_id(\Fiber::getCurrent()),
                    'message' => "All-at-once {$n}/{$count}",
                ], $channel);
                $ids[] = $entry['id'];
            });
        }

        $scope->concurrent($tasks);

        return ['ids' => $ids];
    }

    private static function waterfall(
        ExecutionScope $scope,
        int $count,
        string $channel,
        \Closure $publish,
    ): array {
        $ids = [];
        $capped = min($count, 30);

        $tasks = [];
        for ($i = 1; $i <= $capped; $i++) {
            $n = $i;
            $tasks[] = Task::of(static function (ExecutionScope $es) use ($n, $capped, $channel, $publish, &$ids): int {
                $prev = $es->attribute('_waterfall_previous');
                $entry = $publish([
                    'mode' => 'waterfall',
                    'step' => $n,
                    'total' => $capped,
                    'previous_id' => $prev,
                    'chain' => $ids,
                    'message' => $prev === null
                        ? "Chain start (step {$n}/{$capped})"
                        : "Chained from #{$prev} (step {$n}/{$capped})",
                ], $channel);
                $ids[] = $entry['id'];

                return $entry['id'];
            });
        }

        $scope->waterfall($tasks);

        return ['ids' => $ids, 'capped_at' => $capped];
    }

    private static function settle(
        ExecutionScope $scope,
        int $count,
        string $channel,
        \Closure $publish,
    ): array {
        $ids = [];
        $channels = ['test', 'app', 'database', 'cache', 'queue'];

        $tasks = [];
        for ($i = 1; $i <= $count; $i++) {
            $n = $i;
            $shouldFail = $n % 7 === 0;
            $ch = $channels[$n % count($channels)];

            $tasks["settle-{$n}"] = Task::of(static function (ExecutionScope $es) use ($n, $count, $shouldFail, $ch, $publish, &$ids): void {
                $es->delay(0.01 * random_int(1, 5));

                if ($shouldFail) {
                    throw new \RuntimeException("Intentional failure on event {$n}");
                }

                $entry = $publish([
                    'mode' => 'settle',
                    'index' => $n,
                    'total' => $count,
                    'channel_used' => $ch,
                    'message' => "Settled {$n}/{$count} on [{$ch}]",
                ], $ch);
                $ids[] = $entry['id'];
            });
        }

        $settlement = $scope->settle($tasks);

        return [
            'ids' => $ids,
            'fulfilled' => count($settlement->values),
            'rejected' => count($settlement->errors),
            'errors' => array_map(
                static fn(\Throwable $e) => $e->getMessage(),
                $settlement->errors,
            ),
        ];
    }
}
