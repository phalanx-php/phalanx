<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload_runtime.php';

use Acme\StoaDemo\Realtime\Bundle\RealtimeBundle;
use Acme\StoaDemo\Realtime\Support\RawHttpRequest;
use Acme\StoaDemo\Realtime\Support\RealtimeReporter;
use Acme\StoaDemo\Realtime\Support\ServerReadiness;
use Acme\StoaDemo\Realtime\Support\SseFrameMatcher;
use Acme\StoaDemo\Realtime\Support\SseFrameReader;
use OpenSwoole\Coroutine;
use OpenSwoole\Process;
use Phalanx\Stoa\Stoa;

return static function (array $context): \Closure {
    $host = '127.0.0.1';
    $port = isset($argv[1]) ? (int) $argv[1] : random_int(20_000, 45_000);
    $listen = "{$host}:{$port}";

    $server = new Process(static function () use ($listen, $context): void {
        Stoa::starting($context)
            ->providers(new RealtimeBundle())
            ->routes(__DIR__ . '/routes.php')
            ->listen($listen)
            ->quiet()
            ->run();
    });

    $pid = $server->start();
    if ($pid === false) {
        fwrite(STDERR, "unable to start server\n");
        return static fn(): int => 1;
    }

    $failed = false;

    try {
        echo "Phalanx Realtime Demo\n";
        echo "server  starting {$listen}\n\n";

        Coroutine::run(static function () use ($host, $port, &$failed): void {
            $report = new RealtimeReporter();
            $checkReady = new ServerReadiness();
            $httpRaw = new RawHttpRequest();
            $readSse = new SseFrameReader();
            $allFramesHaveEvent = new SseFrameMatcher();

            $ready = $checkReady($host, $port);
            $failed = !$report('server ready on /realtime/health', $ready) || $failed;

            // SSE: stream 5 frames, verify count, ids, format
            $sse = $readSse($host, $port, '/realtime/counter', 5, 3.0);
            $failed = !$report('sse status line is 200 OK', $sse['status'] === 200) || $failed;
            $failed = !$report('sse content-type is text/event-stream', str_contains($sse['headers'], 'text/event-stream')) || $failed;
            $failed = !$report('sse received 5 frames', count($sse['frames']) === 5) || $failed;
            $failed = !$report('sse first frame data is "tick 1"', ($sse['frames'][0]['data'] ?? '') === 'tick 1') || $failed;
            $failed = !$report('sse last frame id is "5"', ($sse['frames'][4]['id'] ?? '') === '5') || $failed;
            $failed = !$report('sse all frames carry event: count', $allFramesHaveEvent($sse['frames'], 'count')) || $failed;

            // HTTP upgrade seam: no registrar means 426
            $upgrade = $httpRaw($host, $port, 'GET', '/realtime/somewhere', [
                'Upgrade: websocket',
                'Connection: Upgrade',
            ]);
            $failed = !$report('upgrade without registrar -> 426', $upgrade['status'] === 426) || $failed;

            // Outbound HTTP client: server proxies to itself
            $proxy = $httpRaw($host, $port, 'GET', "/realtime/proxy?upstream_port={$port}");
            $failed = !$report('proxy returns 200', $proxy['status'] === 200) || $failed;
            $body = json_decode($proxy['body'], true);
            $failed = !$report('proxy body is JSON {status: ok}', is_array($body) && ($body['status'] ?? null) === 'ok') || $failed;

            // Health still up after the dance
            $stillReady = $httpRaw($host, $port, 'GET', '/realtime/health');
            $failed = !$report('server still healthy after demo', $stillReady['status'] === 200) || $failed;
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

    return static fn(): int => $failed ? 1 : 0;
};
