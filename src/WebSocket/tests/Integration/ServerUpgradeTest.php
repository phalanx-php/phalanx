<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Integration;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Runner;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\WebSocket\Gateway;
use Phalanx\WebSocket\RouteGroup as WebSocketRouteGroup;
use Phalanx\WebSocket\Server\Upgrade;
use Phalanx\WebSocket\WebSocket;
use PHPUnit\Framework\Attributes\Test;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server as SwooleServer;

/**
 * Wiring proof for the HTTP/WebSocket upgrade path.
 *
 * The actual handshake call ({@see SwooleResponse::upgrade()}) is a
 * native, non-stubbable C method that mutates kernel state — exercising it
 * outside a real {@see SwooleServer} context is undefined. This
 * test therefore asserts the registration plane up to the moment HTTP hands
 * off to the {@see Upgrade} instance: token resolution, registry
 * shape, and the missing-registrar contract that returns 426.
 *
 * The post-handshake managed-resource transitions (HTTP request -> upgraded
 * generation, retyped to WebSocketServerConnection, terminal session_ended
 * outcome) are unit-tested in the server upgrade unit coverage
 * with a coroutine-driven mock target; here we only prove the wiring.
 */
final class ServerUpgradeTest extends PhalanxTestCase
{
    #[Test]
    public function websocketInstallRegistersWebsocketUpgradeToken(): void
    {
        $testApp = $this->testApp([], WebSocket::services());

        $this->scope->run(static function (ExecutionScope $_scope) use ($testApp): void {
            $app = $testApp->start()->hostForInternalTesting();

            $runner = Runner::from($app)->withRoutes(RouteGroup::of([]));

            self::assertNull(
                $runner->upgrades()->resolve(WebSocket::UPGRADE_TOKEN),
                'token must be unresolved before WebSocket::install',
            );
            self::assertCount(0, $runner->upgrades()->tokens());

            WebSocket::install($runner, $app, WebSocketRouteGroup::of([], new Gateway()));

            $resolved = $runner->upgrades()->resolve(WebSocket::UPGRADE_TOKEN);
            self::assertInstanceOf(Upgrade::class, $resolved);
            self::assertContains(
                WebSocket::UPGRADE_TOKEN,
                $runner->upgrades()->tokens(),
                'tokens() must surface the registered upgrade',
            );
            self::assertTrue(
                $runner->upgrades()->supports(WebSocket::UPGRADE_TOKEN),
                'supports() must return true for the registered token',
            );
        });
    }

    #[Test]
    public function upgradeRequestWithoutWebSocketInstallReturns426(): void
    {
        $testApp = $this->testApp([], WebSocket::services());

        $this->scope->run(static function (ExecutionScope $_scope) use ($testApp): void {
            $app = $testApp->start()->hostForInternalTesting();

            $runner = Runner::from($app)->withRoutes(RouteGroup::of([]));

            $response = $runner->dispatch(
                new ServerRequest('GET', '/socket')
                    ->withHeader('Upgrade', 'websocket')
                    ->withHeader('Connection', 'Upgrade'),
            );

            self::assertSame(
                426,
                $response->getStatusCode(),
                'unupgraded ws request must yield 426 Upgrade Required',
            );
        });
    }

    #[Test]
    public function upgradeRequestAfterInstallResolvesToWebSocket(): void
    {
        $testApp = $this->testApp([], WebSocket::services());

        $this->scope->run(static function (ExecutionScope $_scope) use ($testApp): void {
            $app = $testApp->start()->hostForInternalTesting();

            $runner = Runner::from($app)->withRoutes(RouteGroup::of([]));
            WebSocket::install($runner, $app, WebSocketRouteGroup::of([], new Gateway()));

            $resolvedFirst = $runner->upgrades()->resolve(WebSocket::UPGRADE_TOKEN);
            $resolvedSecond = $runner->upgrades()->resolve(WebSocket::UPGRADE_TOKEN);

            self::assertSame(
                $resolvedFirst,
                $resolvedSecond,
                'repeated resolve() calls must return the same singleton instance',
            );
        });
    }
}
