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
    Coroutine::run(static function () use ($events, $host, $port, &$failed): void {
        $failed = !check('server readiness', waitForHttpStatus($host, $port, '/runtime/health', 200));
        $failed = !check('GET /runtime/slow -> 200', waitForHttpStatus($host, $port, '/runtime/slow', 200)) || $failed;
        $failed = !check('slow request completed', waitForEvent($events, 'slow.completed', 2.0)) || $failed;

        $client = openRawRequest($host, $port, '/runtime/disconnect');
        $started = waitForEvent($events, 'disconnect.started', 2.0);
        $client?->close();

        $failed = !check('disconnect request started', $started) || $failed;
        $failed = !check('client disconnect cancelled request', waitForEvent($events, 'disconnect.cancelled', 2.0)) || $failed;
        $failed = !check('disconnect handler finalized', waitForEvent($events, 'disconnect.finalized', 2.0)) || $failed;
        $failed = !check('disconnect did not complete work', !$events->contains('disconnect.completed')) || $failed;
        $failed = !check('server still responds after disconnect', waitForHttpStatus($host, $port, '/runtime/health', 200)) || $failed;
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
    echo "{$label} -> " . ($passed ? 'ok' : 'failed') . PHP_EOL;

    return $passed;
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
