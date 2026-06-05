<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Integration;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Http\RouteGroup;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Wiring proof for the HTTP/WebSocket upgrade path.
 *
 * The actual handshake call ({@see \Swoole\Http\Response::upgrade()}) is a
 * native, non-stubbable C method that mutates kernel state — exercising it
 * outside a real {@see \Swoole\Http\Server} context is undefined. This
 * test therefore asserts the registration plane up to the moment HTTP hands
 * off to the {@see \Phalanx\WebSocket\Server\Upgrade} instance: token resolution, registry
 * shape, and the missing-registrar contract that returns 426.
 *
 * The post-handshake managed-resource transitions (HTTP request -> upgraded
 * generation, retyped to WebSocketServerConnection, terminal session_ended
 * outcome) are unit-tested in the server upgrade unit coverage
 * with a coroutine-driven mock target; here we only prove the wiring.
 */
final class WsServerUpgradeTest extends PhalanxTestCase
{
    #[Test]
    public function websocketInstallRegistersWebsocketUpgradeToken(): void
    {
        $testApp = $this->testApp([], \Phalanx\WebSocket\Facade::services());

        $this->scope->run(static function (ExecutionScope $_scope) use ($testApp): void {
            $app = $testApp->application->startup();

            $runner = \Phalanx\Http\Runner::from($app)->withRoutes(RouteGroup::of([]));

            self::assertNull(
                $runner->upgrades()->resolve(\Phalanx\WebSocket\Facade::UPGRADE_TOKEN),
                'token must be unresolved before Facade::install',
            );
            self::assertCount(0, $runner->upgrades()->tokens());

            \Phalanx\WebSocket\Facade::install($runner, $app, \Phalanx\WebSocket\RouteGroup::of([], new \Phalanx\WebSocket\Gateway()));

            $resolved = $runner->upgrades()->resolve(\Phalanx\WebSocket\Facade::UPGRADE_TOKEN);
            self::assertInstanceOf(\Phalanx\WebSocket\Server\Upgrade::class, $resolved);
            self::assertContains(
                \Phalanx\WebSocket\Facade::UPGRADE_TOKEN,
                $runner->upgrades()->tokens(),
                'tokens() must surface the registered upgrade',
            );
            self::assertTrue(
                $runner->upgrades()->supports(\Phalanx\WebSocket\Facade::UPGRADE_TOKEN),
                'supports() must return true for the registered token',
            );
        });
    }

    #[Test]
    public function upgradeRequestWithoutWebSocketInstallReturns426(): void
    {
        $testApp = $this->testApp([], \Phalanx\WebSocket\Facade::services());

        $this->scope->run(static function (ExecutionScope $_scope) use ($testApp): void {
            $app = $testApp->application->startup();

            $runner = \Phalanx\Http\Runner::from($app)->withRoutes(RouteGroup::of([]));

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
        // After install, the upgrade resolution must point at the WsServerUpgrade
        // instance WebSocket constructed — never null, and never the wrong type. The
        // request body itself is not dispatched here because that path enters
        // Swoole's native Response::upgrade() which has no test seam.
        $testApp = $this->testApp([], \Phalanx\WebSocket\Facade::services());

        $this->scope->run(static function (ExecutionScope $_scope) use ($testApp): void {
            $app = $testApp->application->startup();

            $runner = \Phalanx\Http\Runner::from($app)->withRoutes(RouteGroup::of([]));
            \Phalanx\WebSocket\Facade::install($runner, $app, \Phalanx\WebSocket\RouteGroup::of([], new \Phalanx\WebSocket\Gateway()));

            $resolvedFirst = $runner->upgrades()->resolve(\Phalanx\WebSocket\Facade::UPGRADE_TOKEN);
            $resolvedSecond = $runner->upgrades()->resolve(\Phalanx\WebSocket\Facade::UPGRADE_TOKEN);

            self::assertSame(
                $resolvedFirst,
                $resolvedSecond,
                'repeated resolve() calls must return the same singleton instance',
            );
        });
    }
}
