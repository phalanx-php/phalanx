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

    public static function process(string $command, string $detail = ''): self
    {
        $head = self::firstArgument($command);
        $body = $detail !== '' ? "{$head} ({$detail})" : $head;
        return new self(WaitKind::Process, $body, microtime(true));
    }

    public static function input(string $prompt = '', string $detail = ''): self
    {
        $body = $prompt !== '' && $detail !== ''
            ? "{$prompt} ({$detail})"
            : ($prompt !== '' ? $prompt : $detail);
        return new self(WaitKind::Input, $body, microtime(true));
    }

    public static function streamWrite(string $domain, int $bytes = 0): self
    {
        $body = $bytes > 0 ? "{$domain} ({$bytes}B)" : $domain;
        return new self(WaitKind::StreamWrite, $body, microtime(true));
    }

    public static function wsFrameWrite(string $domain = '', int $bytes = 0): self
    {
        $head = $domain !== '' ? $domain : 'ws.frame';
        $body = $bytes > 0 ? "{$head} ({$bytes}B)" : $head;
        return new self(WaitKind::WsFrameWrite, $body, microtime(true));
    }

    public static function wsFrameRead(string $domain = ''): self
    {
        return new self(WaitKind::WsFrameRead, $domain, microtime(true));
    }

    public static function udpReceive(string $host = '', int $port = 0): self
    {
        $body = $host !== '' && $port > 0 ? "{$host}:{$port}" : $host;
        return new self(WaitKind::UdpReceive, $body, microtime(true));
    }

    public static function custom(string $detail): self
    {
        return new self(WaitKind::Custom, $detail, microtime(true));
    }

    /**
     * Strip a shell command down to the first whitespace-separated token so
     * the wait detail prints as the binary name rather than the full argv
     * line. Long argv lines tend to dominate the diagnostic display.
     */
    private static function firstArgument(string $command): string
    {
        $trimmed = trim($command);
        if ($trimmed === '') {
            return '';
        }
        $head = strtok($trimmed, " \t\n");
        return $head === false ? $trimmed : $head;
    }

    public function elapsed(): float
    {
        return $this->startedAt > 0.0 ? microtime(true) - $this->startedAt : 0.0;
    }
}
