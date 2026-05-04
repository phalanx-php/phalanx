<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\StoaDemo\Realtime\Bundle\RealtimeBundle;
use OpenSwoole\Constant;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Client;
use OpenSwoole\Process;
use Phalanx\Stoa\Stoa;

$host = '127.0.0.1';
$port = isset($argv[1]) ? (int) $argv[1] : random_int(20_000, 45_000);
$listen = "{$host}:{$port}";

$server = new Process(static function () use ($listen): void {
    Stoa::starting()
        ->providers(new RealtimeBundle())
        ->routes(__DIR__ . '/routes.php')
        ->listen($listen)
        ->quiet()
        ->run();
});

$pid = $server->start();
if ($pid === false) {
    fwrite(STDERR, "unable to start server\n");
    exit(1);
}

$failed = false;

try {
    echo "Phalanx Realtime Demo\n";
    echo "server  starting {$listen}\n\n";

    Coroutine::run(static function () use ($host, $port, &$failed): void {
        $ready = waitReady($host, $port);
        $failed = !report('server ready on /realtime/health', $ready) || $failed;

        // SSE: stream 5 frames, verify count, ids, format
        $sse = readSse($host, $port, '/realtime/counter', 5, 3.0);
        $failed = !report('sse status line is 200 OK', $sse['status'] === 200) || $failed;
        $failed = !report('sse content-type is text/event-stream', str_contains($sse['headers'], 'text/event-stream')) || $failed;
        $failed = !report('sse received 5 frames', count($sse['frames']) === 5) || $failed;
        $failed = !report('sse first frame data is "tick 1"', ($sse['frames'][0]['data'] ?? '') === 'tick 1') || $failed;
        $failed = !report('sse last frame id is "5"', ($sse['frames'][4]['id'] ?? '') === '5') || $failed;
        $failed = !report('sse all frames carry event: count', allFramesHaveEvent($sse['frames'], 'count')) || $failed;

        // HTTP upgrade seam: no registrar means 426
        $upgrade = httpRaw($host, $port, 'GET', '/realtime/somewhere', [
            'Upgrade: websocket',
            'Connection: Upgrade',
        ]);
        $failed = !report('upgrade without registrar -> 426', $upgrade['status'] === 426) || $failed;

        // Outbound HTTP client: server proxies to itself
        $proxy = httpRaw($host, $port, 'GET', "/realtime/proxy?upstream_port={$port}");
        $failed = !report('proxy returns 200', $proxy['status'] === 200) || $failed;
        $body = json_decode($proxy['body'], true);
        $failed = !report('proxy body is JSON {status: ok}', is_array($body) && ($body['status'] ?? null) === 'ok') || $failed;

        // Health still up after the dance
        $stillReady = httpRaw($host, $port, 'GET', '/realtime/health');
        $failed = !report('server still healthy after demo', $stillReady['status'] === 200) || $failed;
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
}

echo "\n";
echo $failed ? "demo failed\n" : "demo passed\n";
exit($failed ? 1 : 0);

function report(string $label, bool $passed): bool
{
    echo "  " . ($passed ? 'ok    ' : 'FAIL  ') . $label . PHP_EOL;
    return $passed;
}

function waitReady(string $host, int $port): bool
{
    $deadline = microtime(true) + 3.0;
    do {
        $response = httpRaw($host, $port, 'GET', '/realtime/health');
        if ($response['status'] === 200) {
            return true;
        }
        Coroutine::usleep(50_000);
    } while (microtime(true) < $deadline);
    return false;
}

/**
 * @param list<string> $extraHeaders
 * @return array{status: int, headers: string, body: string}
 */
function httpRaw(string $host, int $port, string $method, string $path, array $extraHeaders = []): array
{
    $client = new Client(Constant::SOCK_TCP);
    if (!$client->connect($host, $port, 0.5)) {
        return ['status' => 0, 'headers' => '', 'body' => ''];
    }

    $headers = ["Host: {$host}:{$port}", 'Connection: close', ...$extraHeaders];
    $client->send("{$method} {$path} HTTP/1.1\r\n" . implode("\r\n", $headers) . "\r\n\r\n");

    $raw = '';
    while (true) {
        $chunk = $client->recv(0.5);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    $client->close();

    [$head, $body] = str_contains($raw, "\r\n\r\n")
        ? [substr($raw, 0, strpos($raw, "\r\n\r\n")), substr($raw, strpos($raw, "\r\n\r\n") + 4)]
        : [$raw, ''];
    preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $head, $m);
    return ['status' => (int) ($m[1] ?? 0), 'headers' => $head, 'body' => $body];
}

/**
 * @return array{status: int, headers: string, frames: list<array{event?: string, id?: string, data: string}>}
 */
function readSse(string $host, int $port, string $path, int $expectedFrames, float $timeout): array
{
    $client = new Client(Constant::SOCK_TCP);
    if (!$client->connect($host, $port, 0.5)) {
        return ['status' => 0, 'headers' => '', 'frames' => []];
    }

    $client->send("GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\nAccept: text/event-stream\r\n\r\n");

    $deadline = microtime(true) + $timeout;
    $raw = '';
    $frames = [];
    $headersComplete = false;
    $head = '';

    while (microtime(true) < $deadline && count($frames) < $expectedFrames) {
        $chunk = $client->recv(0.2);
        if ($chunk === false || $chunk === '') {
            continue;
        }
        $raw .= $chunk;

        if (!$headersComplete && str_contains($raw, "\r\n\r\n")) {
            $boundary = strpos($raw, "\r\n\r\n");
            $head = substr($raw, 0, $boundary);
            $raw = substr($raw, $boundary + 4);
            $headersComplete = true;
        }

        if ($headersComplete) {
            while (str_contains($raw, "\n\n")) {
                $boundary = strpos($raw, "\n\n");
                $frameText = substr($raw, 0, $boundary);
                $raw = substr($raw, $boundary + 2);
                $frame = parseFrame($frameText);
                if ($frame !== null) {
                    $frames[] = $frame;
                }
            }
        }
    }

    $client->close();
    preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $head, $m);
    return ['status' => (int) ($m[1] ?? 0), 'headers' => $head, 'frames' => $frames];
}

/** @return array{event?: string, id?: string, data: string}|null */
function parseFrame(string $text): ?array
{
    $frame = ['data' => ''];
    $dataParts = [];
    foreach (explode("\n", $text) as $line) {
        if ($line === '' || str_starts_with($line, ':')) {
            continue;
        }
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $name = substr($line, 0, $colon);
        $value = ltrim(substr($line, $colon + 1));
        if ($name === 'data') {
            $dataParts[] = $value;
        } elseif (in_array($name, ['event', 'id', 'retry'], true)) {
            $frame[$name] = $value;
        }
    }
    if ($dataParts === []) {
        return null;
    }
    $frame['data'] = implode("\n", $dataParts);
    return $frame;
}

/** @param list<array{event?: string, id?: string, data: string}> $frames */
function allFramesHaveEvent(array $frames, string $event): bool
{
    foreach ($frames as $frame) {
        if (($frame['event'] ?? null) !== $event) {
            return false;
        }
    }
    return true;
}
