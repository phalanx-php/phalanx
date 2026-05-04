<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\StoaDemo\ManagedRuntime\AcceptedRoute;
use Acme\StoaDemo\ManagedRuntime\PongRoute;
use GuzzleHttp\Psr7\ServerRequest;
use OpenSwoole\Http\Response as OpenSwooleResponse;
use Phalanx\Application;
use Phalanx\Registry\RegistryScope;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Runtime\Memory\ManagedSwooleTables;
use Phalanx\Server\ServerStats;
use Phalanx\Stoa\Http\Client\Wire\HttpRequestEncoder;
use Phalanx\Stoa\Http\Client\Wire\HttpResponseDecoder;
use Phalanx\Stoa\Http\Client\StoaHttpRequest;
use Phalanx\Stoa\Http\Upgrade\HttpUpgradeable;
use Phalanx\Stoa\Response\ResponseLeaseDomain;
use Phalanx\Stoa\Runtime\Identity\StoaResourceSid;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Sse\SseEncoder;
use Phalanx\Stoa\StoaRequestResource;
use Phalanx\Stoa\StoaRunner;
use Psr\Http\Message\ServerRequestInterface;

echo "Phalanx Managed Runtime Demo\n\n";

$failed = false;

// ── Section 1: dispatch lifecycle leaves no leases held
section('delivery lease lifecycle (dispatch path)');

$app = Application::starting()->compile()->startup();
try {
    $runner = StoaRunner::from($app)->withRoutes(RouteGroup::of([
        'GET /pong' => PongRoute::class,
    ]));

    $response = $runner->dispatch(new ServerRequest('GET', '/pong'));

    $leaseRowsRemaining = leaseCount($app, ResponseLeaseDomain::DOMAIN);

    $failed = report('dispatch returned 200', $response->getStatusCode() === 200) || $failed;
    $failed = report('dispatch body matches', (string) $response->getBody() === 'pong') || $failed;
    $failed = report('no stoa.response leases left held after dispatch', $leaseRowsRemaining === 0) || $failed;
} finally {
    $app->shutdown();
}

// ── Section 2: active-request scope queries flow through ServerStats
section('active-request scope queries');

$app = Application::starting()->compile()->startup();
try {
    $runner = StoaRunner::from($app)
        ->withRoutes(RouteGroup::of([]))
        ->withServerStats(ServerStats::fromArray(['connection_num' => 17]));

    $worker = $runner->activeRequests(RegistryScope::Worker);
    $server = $runner->activeRequests(RegistryScope::Server);
    $byState = $runner->activeRequestsByState(RegistryScope::Worker);

    $failed = report('worker scope reports 0 idle', $worker === 0) || $failed;
    $failed = report('server scope reports 17 connections', $server === 17) || $failed;
    $failed = report('worker by-state breakdown is empty when idle', $byState === []) || $failed;
} finally {
    $app->shutdown();
}

// ── Section 3: SSE encoder emits spec-compliant frames
section('sse encoder produces spec-compliant frames');

$single = SseEncoder::event('hello');
$multi = SseEncoder::event("a\nb", event: 'tick', id: '7', retryMs: 250);
$comment = SseEncoder::comment('keep-alive');

$failed = report('single-line frame matches `data: hello\\n\\n`', $single === "data: hello\n\n") || $failed;
$failed = report('multi-line frame splits per spec', $multi === "id: 7\nevent: tick\nretry: 250\ndata: a\ndata: b\n\n") || $failed;
$failed = report('comment frame uses colon prefix', $comment === ": keep-alive\n\n") || $failed;

// ── Section 4: HTTP upgrade seam — 426 without registrar, delegated with registrar
section('http upgrade seam');

$app = Application::starting()->compile()->startup();
try {
    $runner = StoaRunner::from($app)->withRoutes(RouteGroup::of([]));

    $upgradeRequest = (new ServerRequest('GET', '/socket'))
        ->withHeader('Upgrade', 'websocket')
        ->withHeader('Connection', 'Upgrade');

    $response = $runner->dispatch($upgradeRequest);

    $failed = report('unregistered upgrade returns 426', $response->getStatusCode() === 426) || $failed;
    $failed = report('426 advertises empty Upgrade list', $response->getHeaderLine('Upgrade') === '') || $failed;

    $runner->upgrades()->register('h2c', new class () implements HttpUpgradeable {
        public function upgrade(
            ServerRequestInterface $request,
            OpenSwooleResponse $target,
            StoaRequestResource $requestResource,
        ): ManagedResourceHandle {
            throw new RuntimeException('test stub: not invoked because dispatch() has no target');
        }
    });

    $supportedRequest = (new ServerRequest('GET', '/h2c'))
        ->withHeader('Upgrade', 'h2c')
        ->withHeader('Connection', 'Upgrade');

    // dispatch() has no real OpenSwoole target, so the upgradeable is never invoked, but
    // we can verify the registry sees the registered token.
    $failed = report('registry resolves registered token', $runner->upgrades()->resolve('h2c') !== null) || $failed;
    $failed = report('registry refuses unknown token', $runner->upgrades()->resolve('mqtt') === null) || $failed;
} finally {
    $app->shutdown();
}

// ── Section 5: outbound HTTP wire round-trip (encoder/decoder)
section('outbound http wire round-trip');

$encoded = HttpRequestEncoder::encode(StoaHttpRequest::post('http://example.com/echo', '{"x":1}'), userAgent: 'demo/1.0');
$wireResponse = "HTTP/1.1 202 Accepted\r\nContent-Length: 7\r\nContent-Type: text/plain\r\n\r\npending";
$response = HttpResponseDecoder::decode($wireResponse);

$failed = report('encoded request includes Host: example.com', str_contains($encoded['request'], 'Host: example.com')) || $failed;
$failed = report('encoded request includes Content-Length: 7', str_contains($encoded['request'], 'Content-Length: 7')) || $failed;
$failed = report('encoded request includes User-Agent: demo/1.0', str_contains($encoded['request'], 'User-Agent: demo/1.0')) || $failed;
$failed = report('decoded response status is 202', $response->status === 202) || $failed;
$failed = report('decoded response body is "pending"', $response->body === 'pending') || $failed;

$chunkedWire = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n";
$chunkedDecoded = HttpResponseDecoder::decode($chunkedWire);
$failed = report('chunked-encoded body decodes to "hello world"', $chunkedDecoded->body === 'hello world') || $failed;

// ── Section 6: managed-resource Sids carry stable identity contracts
section('managed-resource sid identity contract');

$expected = [
    StoaResourceSid::HttpRequest->value() => 'stoa.http_request',
    StoaResourceSid::SseStream->value() => 'stoa.sse_stream',
    StoaResourceSid::WsConnection->value() => 'stoa.ws_connection',
    StoaResourceSid::OutboundHttpRequest->value() => 'stoa.outbound_http_request',
    StoaResourceSid::UdpListener->value() => 'stoa.udp_listener',
    StoaResourceSid::UdpSession->value() => 'stoa.udp_session',
];

$ok = true;
foreach ($expected as $actual => $expectedValue) {
    $ok = $ok && $actual === $expectedValue;
}
$failed = report('all six StoaResourceSid cases serialize to documented values', $ok) || $failed;
$failed = report('sid count is exactly 6', count(StoaResourceSid::cases()) === 6) || $failed;

echo "\n";
echo $failed ? "demo failed\n" : "demo passed\n";
exit($failed ? 1 : 0);

function section(string $title): void
{
    echo "{$title}\n";
}

function report(string $label, bool $passed): bool
{
    echo "  " . ($passed ? 'ok    ' : 'FAIL  ') . $label . PHP_EOL;

    return !$passed;
}

function leaseCount(\Phalanx\Application $app, string $domain): int
{
    $count = 0;
    foreach ($app->runtime()->memory->tables->resourceLeases as $row) {
        if (is_array($row) && (string) $row['domain'] === $domain) {
            $count++;
        }
    }

    return $count;
}
