<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\StoaDemo\Runtime\RuntimeLifecycleBundle;
use Acme\StoaDemo\Runtime\Support\RuntimeEvents;
use OpenSwoole\Constant;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Client;
use OpenSwoole\Process;
use Phalanx\Stoa\Stoa;

$host = '127.0.0.1';
$port = isset($argv[1]) ? (int) $argv[1] : random_int(20_000, 45_000);
$listen = "{$host}:{$port}";
$eventLog = sys_get_temp_dir() . '/phalanx-stoa-runtime-lifecycle-demo-' . getmypid() . '.jsonl';
$events = new RuntimeEvents($eventLog);

@unlink($eventLog);

$server = new Process(static function () use ($eventLog, $listen): void {
    Stoa::starting(['runtime_event_log' => $eventLog])
        ->providers(new RuntimeLifecycleBundle())
        ->routes(__DIR__ . '/routes.php')
        ->listen($listen)
        ->quiet()
        ->run();
});
$pid = $server->start();

if ($pid === false) {
    fwrite(STDERR, "Unable to start OpenSwoole demo process.\n");
    exit(1);
}

$failed = false;

try {
    echo "Phalanx Runtime Lifecycle Demo\n";
    echo "server        starting {$listen}\n\n";

    Coroutine::run(static function () use ($events, $host, $port, &$failed): void {
        $ready = waitForHttpStatus($host, $port, '/runtime/health', 200);
        $slowOk = waitForHttpStatus($host, $port, '/runtime/slow', 200);
        $slowCompleted = waitForEvent($events, 'slow.completed', 2.0);

        printTimeline('normal request', [
            ['server', $ready ? 'accepted health check' : 'did not answer health check'],
            ['request', 'GET /runtime/slow opened'],
            ['handler', eventText($events, 'slow.started', 'started cooperative work')],
            ['handler', eventText($events, 'slow.completed', 'completed cooperative work')],
            ['response', $slowOk ? '200 OK' : 'missing expected response'],
        ]);

        $failed = !check('server readiness', $ready);
        $failed = !check('GET /runtime/slow -> 200', $slowOk) || $failed;
        $failed = !check('slow request completed', $slowCompleted) || $failed;

        $client = openRawRequest($host, $port, '/runtime/disconnect');
        $started = waitForEvent($events, 'disconnect.started', 2.0);
        $client?->close();

        $cancelled = waitForEvent($events, 'disconnect.cancelled', 2.0);
        $finalized = waitForEvent($events, 'disconnect.finalized', 2.0);
        $didNotComplete = !$events->contains('disconnect.completed');
        $healthyAfterDisconnect = waitForHttpStatus($host, $port, '/runtime/health', 200);

        printTimeline('client disconnect', [
            ['request', $started ? 'GET /runtime/disconnect opened' : 'request did not reach handler'],
            ['client', 'closed socket before response'],
            ['scope', eventText($events, 'disconnect.cancelled', 'cancelled by Stoa close event')],
            ['handler', eventText($events, 'disconnect.finalized', 'ran cleanup in finally')],
            ['work', $didNotComplete ? 'did not complete after cancellation' : 'completed unexpectedly'],
            ['server', $healthyAfterDisconnect ? 'accepted next health check' : 'did not answer next health check'],
        ]);

        $failed = !check('disconnect request started', $started) || $failed;
        $failed = !check('client disconnect cancelled request', $cancelled) || $failed;
        $failed = !check('disconnect handler finalized', $finalized) || $failed;
        $failed = !check('disconnect did not complete work', $didNotComplete) || $failed;
        $failed = !check('server still responds after disconnect', $healthyAfterDisconnect) || $failed;
    });
} finally {
    Process::kill($pid, SIGTERM);

    $deadline = microtime(true) + 3.0;
    do {
        $status = Process::wait(false);
        if ($status !== false) {
            break;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);

    if (($status ?? false) === false) {
        Process::kill($pid, SIGKILL);
        Process::wait(false);
    }

    @unlink($eventLog);
}

exit($failed ? 1 : 0);

function check(string $label, bool $passed): bool
{
    echo "  " . ($passed ? 'ok' : 'failed') . "  {$label}" . PHP_EOL;

    return $passed;
}

/** @param list<array{string, string}> $rows */
function printTimeline(string $title, array $rows): void
{
    echo "{$title}\n";

    foreach ($rows as [$actor, $message]) {
        printf("  %-10s %s\n", $actor, $message);
    }

    echo "\n";
}

function eventText(RuntimeEvents $events, string $event, string $fallback): string
{
    $entry = firstEvent($events, $event);

    if ($entry === null) {
        return "missing {$event}";
    }

    $path = (string) ($entry['context']['path'] ?? '');

    return $path !== ''
        ? "{$fallback} ({$path})"
        : $fallback;
}

/** @return array{event: string, context: array<string, mixed>, at: float}|null */
function firstEvent(RuntimeEvents $events, string $event): ?array
{
    foreach ($events->all() as $entry) {
        if ($entry['event'] === $event) {
            return $entry;
        }
    }

    return null;
}

function waitForHttpStatus(string $host, int $port, string $path, int $status): bool
{
    $deadline = microtime(true) + 3.0;

    do {
        $response = httpGet($host, $port, $path);
        if ($response['status'] === $status) {
            return true;
        }

        Coroutine::usleep(50_000);
    } while (microtime(true) < $deadline);

    return false;
}

function waitForEvent(RuntimeEvents $events, string $event, float $timeout): bool
{
    $deadline = microtime(true) + $timeout;

    do {
        if ($events->contains($event)) {
            return true;
        }

        Coroutine::usleep(25_000);
    } while (microtime(true) < $deadline);

    return false;
}

/** @return array{status: int, body: string} */
function httpGet(string $host, int $port, string $path): array
{
    $client = openRawRequest($host, $port, $path);

    if ($client === null) {
        return ['status' => 0, 'body' => ''];
    }

    $raw = '';
    while (true) {
        $chunk = $client->recv(0.25);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    $client->close();

    preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $raw, $matches);
    $body = str_contains($raw, "\r\n\r\n")
        ? substr($raw, strpos($raw, "\r\n\r\n") + 4)
        : '';

    return ['status' => (int) ($matches[1] ?? 0), 'body' => $body];
}

function openRawRequest(string $host, int $port, string $path): ?Client
{
    $client = new Client(Constant::SOCK_TCP);

    if (!$client->connect($host, $port, 0.5)) {
        return null;
    }

    $client->send(
        "GET {$path} HTTP/1.1\r\n"
        . "Host: {$host}:{$port}\r\n"
        . "Connection: close\r\n"
        . "\r\n",
    );

    return $client;
}
