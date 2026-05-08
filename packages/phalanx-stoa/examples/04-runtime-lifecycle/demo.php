<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload_runtime.php';

use Acme\StoaDemo\Runtime\RuntimeLifecycleBundle;
use Acme\StoaDemo\Runtime\Support\EventCollectionContains;
use Acme\StoaDemo\Runtime\Support\EventTextExtractor;
use Acme\StoaDemo\Runtime\Support\FirstEventFinder;
use Acme\StoaDemo\Runtime\Support\HttpStatusWaiter;
use Acme\StoaDemo\Runtime\Support\LifecycleAssertions;
use Acme\StoaDemo\Runtime\Support\RawConnectionOpener;
use Acme\StoaDemo\Runtime\Support\SimpleHttpGet;
use Acme\StoaDemo\Runtime\Support\TimelinePrinter;
use Acme\StoaDemo\Runtime\Support\EventWaiter;
use OpenSwoole\Coroutine;
use OpenSwoole\Process;
use Phalanx\Stoa\Runtime\Identity\StoaEventSid;
use Phalanx\Stoa\Stoa;

return static function (array $context): \Closure {
    $host = '127.0.0.1';
    $port = isset($argv[1]) ? (int) $argv[1] : random_int(20_000, 45_000);
    $listen = "{$host}:{$port}";

    $server = new Process(static function () use ($listen, $context): void {
        Stoa::starting($context)
            ->providers(new RuntimeLifecycleBundle())
            ->routes(__DIR__ . '/routes.php')
            ->listen($listen)
            ->quiet()
            ->run();
    });
    $pid = $server->start();

    if ($pid === false) {
        fwrite(STDERR, "Unable to start OpenSwoole demo process.\n");
        return static fn(): int => 1;
    }

    $assertions = new LifecycleAssertions();

    try {
        echo "Phalanx Runtime Lifecycle Demo\n";
        echo "server        starting {$listen}\n\n";

        Coroutine::run(static function () use ($host, $port, $assertions): void {
            $waitStatus = new HttpStatusWaiter();
            $waitEvent = new EventWaiter();
            $timeline = new TimelinePrinter();
            $eventText = new EventTextExtractor();
            $firstEvent = new FirstEventFinder();
            $containsEvent = new EventCollectionContains();
            $httpGet = new SimpleHttpGet();
            $openRaw = new RawConnectionOpener();

            $ready = $waitStatus($host, $port, '/runtime/health', 200);
            $slowOk = $waitStatus($host, $port, '/runtime/slow', 200);
            $slowCompleted = $waitEvent($host, $port, 'slow.completed', 2.0);

            $timeline('normal request', [
                ['server', $ready ? 'accepted health check' : 'did not answer health check'],
                ['request', 'GET /runtime/slow opened'],
                ['handler', $eventText($host, $port, 'slow.started', 'started cooperative work')],
                ['handler', $eventText($host, $port, 'slow.completed', 'completed cooperative work')],
                ['response', $slowOk ? '200 OK' : 'missing expected response'],
            ]);

            $assertions->record('server readiness', $ready);
            $assertions->record('GET /runtime/slow -> 200', $slowOk);
            $assertions->record('slow request completed', $slowCompleted);

            $client = $openRaw($host, $port, '/runtime/disconnect');
            $started = $waitEvent($host, $port, 'disconnect.started', 2.0);
            $disconnectResource = (string) ($firstEvent($host, $port, 'disconnect.started')['context']['resource'] ?? '');
            $client?->close();

            $disconnected = $waitEvent($host, $port, StoaEventSid::ClientDisconnected->value, 2.0, $disconnectResource);
            $aborted = $waitEvent($host, $port, 'resource.aborted', 2.0, $disconnectResource);
            $released = $waitEvent($host, $port, 'resource.released', 2.0, $disconnectResource);
            $didNotComplete = !$containsEvent($host, $port, 'disconnect.completed', $disconnectResource);
            $healthyAfterDisconnect = $waitStatus($host, $port, '/runtime/health', 200);

            $timeline('client disconnect', [
                ['request', $started ? 'GET /runtime/disconnect opened' : 'request did not reach handler'],
                ['client', 'closed socket before response'],
                ['runtime', $eventText($host, $port, StoaEventSid::ClientDisconnected->value, 'detected closed client socket', $disconnectResource)],
                ['resource', $eventText($host, $port, 'resource.aborted', 'marked request resource aborted', $disconnectResource)],
                ['cleanup', $eventText($host, $port, 'resource.released', 'released runtime resource row', $disconnectResource)],
                ['work', $didNotComplete ? 'did not complete after cancellation' : 'completed unexpectedly'],
                ['server', $healthyAfterDisconnect ? 'accepted next health check' : 'did not answer next health check'],
            ]);

            $assertions->record('disconnect request started', $started);
            $assertions->record('client disconnect detected', $disconnected);
            $assertions->record('request resource aborted', $aborted);
            $assertions->record('request resource released', $released);
            $assertions->record('disconnect did not complete work', $didNotComplete);
            $assertions->record('server still responds after disconnect', $healthyAfterDisconnect);

            // Lock-down claims: response leases released, no resource leak after dispatch.
            $scope = $httpGet($host, $port, '/runtime/admin/scope');
            $scopeBody = $scope['status'] === 200 ? json_decode($scope['body'], true) : null;
            $leasesReleased = is_array($scopeBody) && ($scopeBody['response_leases'] ?? null) === [];
            $resourcesReleased = is_array($scopeBody) && ($scopeBody['request_resources'] ?? null) === 0;

            $timeline('managed runtime claims', [
                ['leases', $leasesReleased ? 'no stoa.response leases held idle' : 'leases still held idle'],
                ['resources', $resourcesReleased ? 'request resources fully released' : 'request resources still tracked'],
            ]);

            $assertions->record('response leases released after dispatch', $leasesReleased);
            $assertions->record('request resources released after dispatch', $resourcesReleased);
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

    return static fn(): int => $assertions->hasFailures() ? 1 : 0;
};
