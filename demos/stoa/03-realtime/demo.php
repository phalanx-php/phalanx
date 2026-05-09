<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Acme\StoaDemo\Realtime\Bundle\RealtimeBundle;
use Acme\StoaDemo\Realtime\Support\RawHttpRequest;
use Acme\StoaDemo\Realtime\Support\ServerReadiness;
use Acme\StoaDemo\Realtime\Support\SseFrameMatcher;
use Acme\StoaDemo\Realtime\Support\SseFrameReader;
use OpenSwoole\Coroutine;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Demos\Kit\DemoSubprocess;
use Phalanx\Stoa\Stoa;

return DemoReport::demo(
    'Stoa Realtime',
    static function (DemoReport $report, AppContext $context): void {
        $host = '127.0.0.1';
        $port = isset($GLOBALS['argv'][1]) ? (int) $GLOBALS['argv'][1] : random_int(20_000, 45_000);
        $listen = "{$host}:{$port}";

        $contextValues = $context->values;
        $server = DemoSubprocess::spawn(static function () use ($listen, $contextValues): void {
            Stoa::starting($contextValues)
                ->providers(new RealtimeBundle())
                ->routes(__DIR__ . '/routes.php')
                ->listen($listen)
                ->quiet()
                ->run();
        });

        if ($server === null) {
            $report->cannotRun(
                'unable to start the realtime server subprocess.',
                'verify OpenSwoole is loaded and the port is free.',
            );
            return;
        }

        $report->note(sprintf('server starting %s', $listen));

        try {
            Coroutine::run(static function () use ($host, $port, $report): void {
                $checkReady          = new ServerReadiness();
                $httpRaw             = new RawHttpRequest();
                $readSse             = new SseFrameReader();
                $allFramesHaveEvent  = new SseFrameMatcher();

                $report->record('server ready on /realtime/health', $checkReady($host, $port));

                $sse = $readSse($host, $port, '/realtime/counter', 5, 3.0);
                $report->record('sse status line is 200 OK',           $sse['status'] === 200);
                $report->record('sse content-type is text/event-stream', str_contains($sse['headers'], 'text/event-stream'));
                $report->record('sse received 5 frames',                count($sse['frames']) === 5);
                $report->record('sse first frame data is "tick 1"',     ($sse['frames'][0]['data'] ?? '') === 'tick 1');
                $report->record('sse last frame id is "5"',             ($sse['frames'][4]['id'] ?? '') === '5');
                $report->record('sse all frames carry event: count',    $allFramesHaveEvent($sse['frames'], 'count'));

                $upgrade = $httpRaw($host, $port, 'GET', '/realtime/somewhere', [
                    'Upgrade: websocket',
                    'Connection: Upgrade',
                ]);
                $report->record('upgrade without registrar -> 426', $upgrade['status'] === 426);

                $proxy = $httpRaw($host, $port, 'GET', "/realtime/proxy?upstream_port={$port}");
                $report->record('proxy returns 200', $proxy['status'] === 200);
                $body = json_decode($proxy['body'], true);
                $report->record('proxy body is JSON {status: ok}', is_array($body) && ($body['status'] ?? null) === 'ok');

                $stillReady = $httpRaw($host, $port, 'GET', '/realtime/health');
                $report->record('server still healthy after demo', $stillReady['status'] === 200);
            });
        } finally {
            $server->terminate();
        }
    },
);
