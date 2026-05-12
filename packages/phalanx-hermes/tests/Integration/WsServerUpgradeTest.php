<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Integration;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Application;
use Phalanx\Hermes\Hermes;
use Phalanx\Hermes\Server\WsServerUpgrade;
use Phalanx\Hermes\WsGateway;
use Phalanx\Hermes\WsRouteGroup;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\StoaRunner;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Wiring proof for the Stoa<->Hermes upgrade seam.
 *
 * The actual handshake call ({@see \OpenSwoole\Http\Response::upgrade()}) is a
 * native, non-stubbable C method that mutates kernel state — exercising it
 * outside a real {@see \OpenSwoole\Http\Server} context is undefined. This
 * test therefore asserts the registration plane up to the moment Stoa hands
 * off to the {@see WsServerUpgrade} instance: token resolution, registry
 * shape, and the missing-registrar contract that returns 426.
 *
 * The post-handshake managed-resource transitions (HttpRequest -> upgraded
 * generation, retyped to WebSocketServerConnection, terminal session_ended
 * outcome) are unit-tested in {@see \Phalanx\Hermes\Tests\Server\WsServerUpgradeUnitTest}
 * with a coroutine-driven mock target; here we only prove the wiring.
 */
final class WsServerUpgradeTest extends PhalanxTestCase
{
    #[Test]
    public function hermesInstallRegistersWebsocketUpgradeToken(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $app = Application::starting()
                ->withLedger(new InProcessLedger())
                ->providers(Hermes::services())
                ->compile()
                ->startup();

            try {
                $runner = StoaRunner::from($app)->withRoutes(RouteGroup::of([]));

                self::assertNull(
                    $runner->upgrades()->resolve(Hermes::UPGRADE_TOKEN),
                    'token must be unresolved before Hermes::install',
                );
                self::assertCount(0, $runner->upgrades()->tokens());

                Hermes::install($runner, $app, WsRouteGroup::of([], new WsGateway()));

                $resolved = $runner->upgrades()->resolve(Hermes::UPGRADE_TOKEN);
                self::assertInstanceOf(WsServerUpgrade::class, $resolved);
                self::assertContains(
                    Hermes::UPGRADE_TOKEN,
                    $runner->upgrades()->tokens(),
                    'tokens() must surface the registered upgrade',
                );
                self::assertTrue(
                    $runner->upgrades()->supports(Hermes::UPGRADE_TOKEN),
                    'supports() must return true for the registered token',
                );
            } finally {
                $app->shutdown();
            }
        });
    }

    #[Test]
    public function upgradeRequestWithoutHermesInstallReturns426(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $app = Application::starting()
                ->withLedger(new InProcessLedger())
                ->providers(Hermes::services())
                ->compile()
                ->startup();

            try {
                $runner = StoaRunner::from($app)->withRoutes(RouteGroup::of([]));

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
            } finally {
                $app->shutdown();
            }
        });
    }

    #[Test]
    public function upgradeRequestAfterInstallResolvesToHermes(): void
    {
        // After install, the upgrade resolution must point at the WsServerUpgrade
        // instance Hermes constructed — never null, and never the wrong type. The
        // request body itself is not dispatched here because that path enters
        // OpenSwoole's native Response::upgrade() which has no test seam.
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $app = Application::starting()
                ->withLedger(new InProcessLedger())
                ->providers(Hermes::services())
                ->compile()
                ->startup();

            try {
                $runner = StoaRunner::from($app)->withRoutes(RouteGroup::of([]));
                Hermes::install($runner, $app, WsRouteGroup::of([], new WsGateway()));

                $resolvedFirst = $runner->upgrades()->resolve(Hermes::UPGRADE_TOKEN);
                $resolvedSecond = $runner->upgrades()->resolve(Hermes::UPGRADE_TOKEN);

                self::assertSame(
                    $resolvedFirst,
                    $resolvedSecond,
                    'repeated resolve() calls must return the same singleton instance',
                );
            } finally {
                $app->shutdown();
            }
        });
    }
}
