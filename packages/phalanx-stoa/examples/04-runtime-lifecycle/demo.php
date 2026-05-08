<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload_runtime.php';

use Acme\StoaDemo\Runtime\RuntimeLifecycleBundle;
use OpenSwoole\Constant;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Client;
use OpenSwoole\Process;
use Phalanx\Boot\AppContext;
use Phalanx\Stoa\Runtime\Identity\StoaEventSid;
use Phalanx\Stoa\Stoa;

return static function (array $context): \Closure {
    $appContext = AppContext::fromSymfonyRuntime($context);

    $host = '127.0.0.1';
    $port = isset($argv[1]) ? (int) $argv[1] : random_int(20_000, 45_000);
    $listen = "{$host}:{$port}";

    $server = new Process(static function () use ($listen, $appContext): void {
        Stoa::starting($appContext)
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

    $failed = false;

    try {
        echo "Phalanx Runtime Lifecycle Demo\n";
        echo "server        starting {$listen}\n\n";

        Coroutine::run(static function () use ($host, $port, &$failed): void {
            $ready = waitForHttpStatus($host, $port, '/runtime/health', 200);
            $slowOk = waitForHttpStatus($host, $port, '/runtime/slow', 200);
            $slowCompleted = waitForEvent($host, $port, 'slow.completed', 2.0);

            printTimeline('normal request', [
                ['server', $ready ? 'accepted health check' : 'did not answer health check'],
                ['request', 'GET /runtime/slow opened'],
                ['handler', eventText($host, $port, 'slow.started', 'started cooperative work')],
                ['handler', eventText($host, $port, 'slow.completed', 'completed cooperative work')],
                ['response', $slowOk ? '200 OK' : 'missing expected response'],
            ]);

            $failed = !check('server readiness', $ready);
            $failed = !check('GET /runtime/slow -> 200', $slowOk) || $failed;
            $failed = !check('slow request completed', $slowCompleted) || $failed;

            $client = openRawRequest($host, $port, '/runtime/disconnect');
            $started = waitForEvent($host, $port, 'disconnect.started', 2.0);
            $disconnectResource = (string) (firstEvent($host, $port, 'disconnect.started')['context']['resource'] ?? '');
            $client?->close();

            $disconnected = waitForEvent($host, $port, StoaEventSid::ClientDisconnected->value, 2.0, $disconnectResource);
            $aborted = waitForEvent($host, $port, 'resource.aborted', 2.0, $disconnectResource);
            $released = waitForEvent($host, $port, 'resource.released', 2.0, $disconnectResource);
            $didNotComplete = !containsEvent($host, $port, 'disconnect.completed', $disconnectResource);
            $healthyAfterDisconnect = waitForHttpStatus($host, $port, '/runtime/health', 200);

            printTimeline('client disconnect', [
                ['request', $started ? 'GET /runtime/disconnect opened' : 'request did not reach handler'],
                ['client', 'closed socket before response'],
                ['runtime', eventText($host, $port, StoaEventSid::ClientDisconnected->value, 'detected closed client socket', $disconnectResource)],
                ['resource', eventText($host, $port, 'resource.aborted', 'marked request resource aborted', $disconnectResource)],
                ['cleanup', eventText($host, $port, 'resource.released', 'released runtime resource row', $disconnectResource)],
                ['work', $didNotComplete ? 'did not complete after cancellation' : 'completed unexpectedly'],
                ['server', $healthyAfterDisconnect ? 'accepted next health check' : 'did not answer next health check'],
            ]);

            $failed = !check('disconnect request started', $started) || $failed;
            $failed = !check('client disconnect detected', $disconnected) || $failed;
            $failed = !check('request resource aborted', $aborted) || $failed;
            $failed = !check('request resource released', $released) || $failed;
            $failed = !check('disconnect did not complete work', $didNotComplete) || $failed;
            $failed = !check('server still responds after disconnect', $healthyAfterDisconnect) || $failed;

            // Lock-down claims: response leases released, no resource leak after dispatch.
            $scope = httpGet($host, $port, '/runtime/admin/scope');
            $scopeBody = $scope['status'] === 200 ? json_decode($scope['body'], true) : null;
            $leasesReleased = is_array($scopeBody) && ($scopeBody['response_leases'] ?? null) === [];
            $resourcesReleased = is_array($scopeBody) && ($scopeBody['request_resources'] ?? null) === 0;

            printTimeline('managed runtime claims', [
                ['leases', $leasesReleased ? 'no stoa.response leases held idle' : 'leases still held idle'],
                ['resources', $resourcesReleased ? 'request resources fully released' : 'request resources still tracked'],
            ]);

            $failed = !check('response leases released after dispatch', $leasesReleased) || $failed;
            $failed = !check('request resources released after dispatch', $resourcesReleased) || $failed;
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

    return static fn(): int => $failed ? 1 : 0;
};

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

function eventText(string $host, int $port, string $event, string $fallback, ?string $resource = null): string
{
    $entry = firstEvent($host, $port, $event, $resource);

    if ($entry === null) {
        return "missing {$event}";
    }

    $path = (string) ($entry['context']['path'] ?? '');

    return $path !== ''
        ? "{$fallback} ({$path})"
        : $fallback;
}

/** @return array{event: string, context: array<string, mixed>, at: float}|null */
function firstEvent(string $host, int $port, string $event, ?string $resource = null): ?array
{
    foreach (events($host, $port) as $entry) {
        if ($entry['event'] === $event && eventMatchesResource($entry, $resource)) {
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

function waitForEvent(string $host, int $port, string $event, float $timeout, ?string $resource = null): bool
{
    $deadline = microtime(true) + $timeout;

    do {
        if (containsEvent($host, $port, $event, $resource)) {
            return true;
        }

        Coroutine::usleep(25_000);
    } while (microtime(true) < $deadline);

    return false;
}

function containsEvent(string $host, int $port, string $event, ?string $resource = null): bool
{
    return firstEvent($host, $port, $event, $resource) !== null;
}

/** @param array{event: string, context: array<string, mixed>, at: float} $entry */
function eventMatchesResource(array $entry, ?string $resource): bool
{
    if ($resource === null || $resource === '') {
        return true;
    }

    return ($entry['context']['resource'] ?? null) === $resource;
}

/** @return list<array{event: string, context: array<string, mixed>, at: float}> */
function events(string $host, int $port): array
{
    $response = httpGet($host, $port, '/runtime/events');
    if ($response['status'] !== 200) {
        return [];
    }

    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded) || !isset($decoded['events']) || !is_array($decoded['events'])) {
        return [];
    }

    return $decoded['events'];
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
