<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Integration;

use Phalanx\Application;
use Phalanx\WebSocket\WsConfig;
use Phalanx\WebSocket\WsConnection;
use Phalanx\WebSocket\WsConnectionHandler;
use Phalanx\WebSocket\WsGateway;
use Phalanx\WebSocket\WsMessage;
use Phalanx\WebSocket\WsRoute;
use Phalanx\WebSocket\WsScope;
use Phalanx\Http\RouteParams;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ratchet\RFC6455\Messaging\Frame;
use React\Stream\ThroughStream;

use function React\Async\async;

final class WsConnectionLifecycleTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = Application::starting()->compile();
    }

    protected function tearDown(): void
    {
        $this->app->shutdown();
    }

    #[Test]
    public function connection_receives_inbound_messages_via_stream(): void
    {
        $received = [];

        $route = new WsRoute(fn: static function (WsScope $ws) use (&$received): void {
            $ws->connection->stream($ws)
                ->filter(static fn(WsMessage $m) => $m->isText)
                ->onEach(static function (WsMessage $m) use (&$received): void {
                    $received[] = $m->payload;
                })
                ->take(2)
                ->consume();
        }, config: new WsConfig(pingInterval: 0));

        $gateway = new WsGateway();
        $handler = new WsConnectionHandler($route, $route->config, $gateway);

        $transport = new ThroughStream();

        async(function () use ($handler, $transport): void {
            $handler->handle($this->app->createScope(), $transport, new ServerRequest('GET', '/ws/test'), new RouteParams([]));
        })();

        $this->sendMaskedText($transport, 'message one');
        $this->sendMaskedText($transport, 'message two');

        $this->assertSame(['message one', 'message two'], $received);
        $this->assertSame(1, $gateway->count());
    }

    #[Test]
    public function connection_sends_outbound_messages_to_transport(): void
    {
        $written = [];

        $route = new WsRoute(fn: static function (WsScope $ws): void {
            $ws->connection->sendText('hello from server');
            $ws->connection->close();
        }, config: new WsConfig(pingInterval: 0));

        $gateway = new WsGateway();
        $handler = new WsConnectionHandler($route, $route->config, $gateway);

        $transport = new ThroughStream();
        $transport->on('data', static function (string $data) use (&$written): void {
            $written[] = $data;
        });

        async(function () use ($handler, $transport): void {
            $handler->handle($this->app->createScope(), $transport, new ServerRequest('GET', '/ws/test'), new RouteParams([]));
        })();

        $this->assertNotEmpty($written, 'Expected outbound data written to transport');
    }

    #[Test]
    public function ws_scope_provides_typed_access(): void
    {
        $capturedScope = null;

        $route = new WsRoute(fn: static function (WsScope $ws) use (&$capturedScope): void {
            $capturedScope = $ws;
            $ws->connection->close();
        }, config: new WsConfig(pingInterval: 0, maxMessageSize: 1024));

        $gateway = new WsGateway();
        $handler = new WsConnectionHandler($route, $route->config, $gateway);

        $transport = new ThroughStream();
        $request = new ServerRequest('GET', '/ws/chat/lobby', ['Host' => 'localhost']);
        $params = new RouteParams(['room' => 'lobby']);

        async(function () use ($handler, $transport, $request, $params): void {
            $handler->handle($this->app->createScope(), $transport, $request, $params);
        })();

        $this->assertInstanceOf(WsScope::class, $capturedScope);
        $this->assertInstanceOf(WsConnection::class, $capturedScope->connection);
        $this->assertSame(1024, $capturedScope->config->maxMessageSize);
        $this->assertSame('/ws/chat/lobby', $capturedScope->request->getUri()->getPath());
        $this->assertSame('lobby', $capturedScope->params->get('room'));
    }

    #[Test]
    public function transport_close_completes_channels(): void
    {
        $pumpCompleted = false;

        $route = new WsRoute(fn: static function (WsScope $ws) use (&$pumpCompleted): void {
            $ws->connection->stream($ws)->consume();
            $pumpCompleted = true;
        }, config: new WsConfig(pingInterval: 0));

        $gateway = new WsGateway();
        $handler = new WsConnectionHandler($route, $route->config, $gateway);

        $transport = new ThroughStream();

        async(function () use ($handler, $transport): void {
            $handler->handle($this->app->createScope(), $transport, new ServerRequest('GET', '/ws'), new RouteParams([]));
        })();

        $transport->close();

        $this->assertTrue($pumpCompleted);
    }

    #[Test]
    public function gateway_tracks_connection_during_lifecycle(): void
    {
        $route = new WsRoute(fn: static function (WsScope $ws): void {
            $ws->connection->close();
        }, config: new WsConfig(pingInterval: 0));

        $gateway = new WsGateway();
        $handler = new WsConnectionHandler($route, $route->config, $gateway);

        $transport = new ThroughStream();

        $this->assertSame(0, $gateway->count());

        async(function () use ($handler, $transport): void {
            $handler->handle($this->app->createScope(), $transport, new ServerRequest('GET', '/ws'), new RouteParams([]));
        })();

        $this->assertSame(1, $gateway->count());
    }

    private function sendMaskedText(ThroughStream $transport, string $payload): void
    {
        $frame = new Frame($payload, true, Frame::OP_TEXT);
        $frame->maskPayload();
        $transport->write($frame->getContents());
    }
}
