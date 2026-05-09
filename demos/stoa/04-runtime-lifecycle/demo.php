<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Acme\StoaDemo\Runtime\RuntimeLifecycleBundle;
use Acme\StoaDemo\Runtime\Support\EventCollectionContains;
use Acme\StoaDemo\Runtime\Support\EventTextExtractor;
use Acme\StoaDemo\Runtime\Support\EventWaiter;
use Acme\StoaDemo\Runtime\Support\FirstEventFinder;
use Acme\StoaDemo\Runtime\Support\HttpStatusWaiter;
use Acme\StoaDemo\Runtime\Support\RawConnectionOpener;
use Acme\StoaDemo\Runtime\Support\SimpleHttpGet;
use Acme\StoaDemo\Runtime\Support\TimelinePrinter;
use OpenSwoole\Coroutine;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Demos\Kit\DemoSubprocess;
use Phalanx\Stoa\Runtime\Identity\StoaEventSid;
use Phalanx\Stoa\Stoa;

return DemoReport::demo(
    'Stoa Runtime Lifecycle',
    static function (DemoReport $report, AppContext $context): void {
        $host = '127.0.0.1';
        $port = isset($GLOBALS['argv'][1]) ? (int) $GLOBALS['argv'][1] : random_int(20_000, 45_000);
        $listen = "{$host}:{$port}";

        $contextValues = $context->values;
        $server = DemoSubprocess::spawn(static function () use ($listen, $contextValues): void {
            Stoa::starting($contextValues)
                ->providers(new RuntimeLifecycleBundle())
                ->routes(__DIR__ . '/routes.php')
                ->listen($listen)
                ->quiet()
                ->run();
        });

        if ($server === null) {
            $report->cannotRun(
                'unable to start the lifecycle server subprocess.',
                'verify OpenSwoole is loaded and the port is free.',
            );
            return;
        }

        $report->note(sprintf('server starting %s', $listen));

        try {
            Coroutine::run(static function () use ($host, $port, $report): void {
                $waitStatus     = new HttpStatusWaiter();
                $waitEvent      = new EventWaiter();
                $timeline       = new TimelinePrinter();
                $eventText      = new EventTextExtractor();
                $firstEvent     = new FirstEventFinder();
                $containsEvent  = new EventCollectionContains();
                $httpGet        = new SimpleHttpGet();
                $openRaw        = new RawConnectionOpener();

                $ready          = $waitStatus($host, $port, '/runtime/health', 200);
                $slowOk         = $waitStatus($host, $port, '/runtime/slow', 200);
                $slowCompleted  = $waitEvent($host, $port, 'slow.completed', 2.0);

                $timeline('normal request', [
                    ['server',   $ready ? 'accepted health check' : 'did not answer health check'],
                    ['request',  'GET /runtime/slow opened'],
                    ['handler',  $eventText($host, $port, 'slow.started', 'started cooperative work')],
                    ['handler',  $eventText($host, $port, 'slow.completed', 'completed cooperative work')],
                    ['response', $slowOk ? '200 OK' : 'missing expected response'],
                ]);

                $report->record('server readiness',         $ready);
                $report->record('GET /runtime/slow -> 200', $slowOk);
                $report->record('slow request completed',   $slowCompleted);

                $client = $openRaw($host, $port, '/runtime/disconnect');
                $started = $waitEvent($host, $port, 'disconnect.started', 2.0);
                $disconnectResource = (string) ($firstEvent($host, $port, 'disconnect.started')['context']['resource'] ?? '');
                $client?->close();

                $disconnected           = $waitEvent($host, $port, StoaEventSid::ClientDisconnected->value, 2.0, $disconnectResource);
                $aborted                = $waitEvent($host, $port, 'resource.aborted', 2.0, $disconnectResource);
                $released               = $waitEvent($host, $port, 'resource.released', 2.0, $disconnectResource);
                $didNotComplete         = !$containsEvent($host, $port, 'disconnect.completed', $disconnectResource);
                $healthyAfterDisconnect = $waitStatus($host, $port, '/runtime/health', 200);

                $timeline('client disconnect', [
                    ['request',  $started ? 'GET /runtime/disconnect opened' : 'request did not reach handler'],
                    ['client',   'closed socket before response'],
                    ['runtime',  $eventText($host, $port, StoaEventSid::ClientDisconnected->value, 'detected closed client socket', $disconnectResource)],
                    ['resource', $eventText($host, $port, 'resource.aborted', 'marked request resource aborted', $disconnectResource)],
                    ['cleanup',  $eventText($host, $port, 'resource.released', 'released runtime resource row', $disconnectResource)],
                    ['work',     $didNotComplete ? 'did not complete after cancellation' : 'completed unexpectedly'],
                    ['server',   $healthyAfterDisconnect ? 'accepted next health check' : 'did not answer next health check'],
                ]);

                $report->record('disconnect request started',                    $started);
                $report->record('client disconnect detected',                    $disconnected);
                $report->record('request resource aborted',                      $aborted);
                $report->record('request resource released',                     $released);
                $report->record('disconnect did not complete work',              $didNotComplete);
                $report->record('server still responds after disconnect',        $healthyAfterDisconnect);

                $scope = $httpGet($host, $port, '/runtime/admin/scope');
                $scopeBody = $scope['status'] === 200 ? json_decode($scope['body'], true) : null;
                $leasesReleased    = is_array($scopeBody) && ($scopeBody['response_leases'] ?? null) === [];
                $resourcesReleased = is_array($scopeBody) && ($scopeBody['request_resources'] ?? null) === 0;

                $timeline('managed runtime claims', [
                    ['leases',    $leasesReleased ? 'no stoa.response leases held idle' : 'leases still held idle'],
                    ['resources', $resourcesReleased ? 'request resources fully released' : 'request resources still tracked'],
                ]);

                $report->record('response leases released after dispatch',   $leasesReleased);
                $report->record('request resources released after dispatch', $resourcesReleased);
            });
        } finally {
            $server->terminate();
        }
    },
);
