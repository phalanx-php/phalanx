<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

/**
 * Why a TaskRun is currently parked. Recorded by every framework suspend
 * point — Suspendable::call(), TaskExecutor::delay(), HttpClient,
 * PostgresPool, Worker::submit(), SingleflightGroup::do(), lock acquire,
 * Channel pop. Drives the live task-tree diagnostic surface.
 *
 * The framework records the kind plus a short free-form detail string
 * suitable for printing alongside a task name. Examples:
 *
 *   delay 2.000s
 *   http GET 127.0.0.1:8080 /slow
 *   postgres SELECT * FROM users WHERE id = '...'
 *   worker agent-2 / SummarizeDocument
 *   singleflight user:42
 *   lock cache/user:42/write
 *
 * Wait reasons are immutable value objects; the supervisor pairs them
 * with a TaskRun id when storing in the ledger.
 */
final readonly class WaitReason
{
    public function __construct(
        public WaitKind $kind,
        public string $detail = '',
        public float $startedAt = 0.0,
    ) {
    }

    public static function delay(float $seconds): self
    {
        return new self(WaitKind::Delay, sprintf('%.3fs', $seconds), microtime(true));
    }

    public static function http(string $method, string $url): self
    {
        return new self(WaitKind::Http, "{$method} {$url}", microtime(true));
    }

    public static function postgres(string $sql): self
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $preview = mb_strlen($normalized) > 80 ? mb_substr($normalized, 0, 77) . '...' : $normalized;
        return new self(WaitKind::Postgres, $preview, microtime(true));
    }

    public static function redis(string $command): self
    {
        return new self(WaitKind::Redis, $command, microtime(true));
    }

    public static function worker(string $workerId, string $taskName): self
    {
        return new self(WaitKind::Worker, "{$workerId} / {$taskName}", microtime(true));
    }

    public static function singleflight(string $key): self
    {
        return new self(WaitKind::Singleflight, $key, microtime(true));
    }

    public static function lock(string $domain, string $key, string $mode): self
    {
        return new self(WaitKind::Lock, "{$domain}/{$key}/{$mode}", microtime(true));
    }

    public static function channel(string $name = ''): self
    {
        return new self(WaitKind::Channel, $name, microtime(true));
    }

    public static function custom(string $detail): self
    {
        return new self(WaitKind::Custom, $detail, microtime(true));
    }

    public function elapsed(): float
    {
        return $this->startedAt > 0.0 ? microtime(true) - $this->startedAt : 0.0;
    }
}
