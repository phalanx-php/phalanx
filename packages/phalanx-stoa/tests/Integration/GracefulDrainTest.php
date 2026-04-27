<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration;

use Phalanx\Application;
use Phalanx\AppHost;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Runner;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Tests\Stoa\Fixtures\EventTrackingSlowHandler;
use Phalanx\Tests\Stoa\Fixtures\SlowHandler;
use Phalanx\Tests\Stoa\Fixtures\StuckHandler;
use Phalanx\Tests\Stoa\Fixtures\Routes\StatusOk;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Socket\SocketServer;

final class GracefulDrainTest extends TestCase
{
    private const string HOST = '127.0.0.1';

    #[Test]
    public function inflight_request_completes_within_drain_timeout(): void
    {
        $port = self::findFreePort();
        $responseBody = null;
        $responseStatus = null;

        $app = Application::starting()->compile();
        $runner = Runner::from($app, requestTimeout: 5.0, drainTimeout: 2.0);
        $runner->withRoutes(RouteGroup::of([
            'GET /slow' => SlowHandler::class,
        ]));

        Loop::futureTick(static function () use ($runner, $port, &$responseBody, &$responseStatus): void {
            $browser = new Browser();
            $url = sprintf('http://%s:%d/slow', self::HOST, $port);

            $browser->get($url)->then(
                static function ($response) use (&$responseBody, &$responseStatus): void {
                    $responseStatus = $response->getStatusCode();
                    $responseBody = (string) $response->getBody();
                },
            );

            Loop::addTimer(0.1, static function () use ($runner): void {
                $runner->stop();
            });
        });

        self::addSafetyTimeout(4.0);
        $runner->run(self::HOST . ':' . $port);

        $this->assertSame(200, $responseStatus);
        $this->assertStringContainsString('completed', $responseBody ?? '');
    }

    #[Test]
    public function drain_timeout_forces_shutdown_on_stuck_request(): void
    {
        $port = self::findFreePort();
        $startTime = null;

        $app = Application::starting()->compile();
        $runner = Runner::from($app, requestTimeout: 10.0, drainTimeout: 0.3);
        $runner->withRoutes(RouteGroup::of([
            'GET /stuck' => StuckHandler::class,
        ]));

        Loop::futureTick(static function () use ($runner, $port, &$startTime): void {
            $browser = new Browser();
            $url = sprintf('http://%s:%d/stuck', self::HOST, $port);

            $browser->get($url)->then(null, static function (): void {});

            Loop::addTimer(0.1, static function () use ($runner, &$startTime): void {
                $startTime = hrtime(true);
                $runner->stop();
            });
        });

        self::addSafetyTimeout(3.0);
        $runner->run(self::HOST . ':' . $port);

        $this->assertNotNull($startTime);
        $elapsed = (hrtime(true) - $startTime) / 1e9;
        $this->assertLessThan(1.0, $elapsed, 'Drain timeout should force shutdown within ~0.3s');
    }

    #[Test]
    public function immediate_finalization_when_no_active_requests(): void
    {
        $port = self::findFreePort();
        $startTime = null;

        $app = Application::starting()->compile();
        $runner = Runner::from($app, requestTimeout: 5.0, drainTimeout: 5.0);
        $runner->withRoutes(RouteGroup::of([
            'GET /health' => StatusOk::class,
        ]));

        Loop::futureTick(static function () use ($runner, &$startTime): void {
            $startTime = hrtime(true);
            $runner->stop();
        });

        self::addSafetyTimeout(2.0);
        $runner->run(self::HOST . ':' . $port);

        $elapsed = (hrtime(true) - $startTime) / 1e9;
        $this->assertLessThan(0.1, $elapsed, 'Should finalize immediately with zero in-flight requests');
    }

    #[Test]
    public function multiple_concurrent_requests_all_drain(): void
    {
        $port = self::findFreePort();
        $completed = 0;

        $app = Application::starting()->compile();
        $runner = Runner::from($app, requestTimeout: 5.0, drainTimeout: 2.0);
        $runner->withRoutes(RouteGroup::of([
            'GET /slow' => SlowHandler::class,
        ]));

        Loop::futureTick(static function () use ($runner, $port, &$completed): void {
            $browser = new Browser();
            $url = sprintf('http://%s:%d/slow', self::HOST, $port);

            for ($i = 0; $i < 5; $i++) {
                $browser->get($url)->then(
                    static function () use (&$completed): void {
                        ++$completed;
                    },
                );
            }

            Loop::addTimer(0.05, static function () use ($runner): void {
                $runner->stop();
            });
        });

        self::addSafetyTimeout(4.0);
        $runner->run(self::HOST . ':' . $port);

        $this->assertSame(5, $completed, 'All 5 concurrent requests should complete during drain');
    }

    #[Test]
    public function service_shutdown_hooks_fire_after_drain(): void
    {
        $shutdownFired = false;

        $bundle = new class($shutdownFired) implements ServiceBundle {
            public function __construct(private bool &$shutdownFired) {}

            public function services(Services $services, array $context): void
            {
                $fired = &$this->shutdownFired;
                $services->eager(\stdClass::class)
                    ->factory(static function () {
                        return new \stdClass();
                    })
                    ->onShutdown(static function () use (&$fired): void {
                        $fired = true;
                    });
            }
        };

        EventTrackingSlowHandler::$events = [];

        $app = Application::starting()->providers($bundle)->compile();
        $port = self::findFreePort();

        $runner = Runner::from($app, requestTimeout: 5.0, drainTimeout: 2.0);
        $runner->withRoutes(RouteGroup::of([
            'GET /slow' => EventTrackingSlowHandler::class,
        ]));

        Loop::futureTick(static function () use ($runner, $port): void {
            $browser = new Browser();
            $url = sprintf('http://%s:%d/slow', self::HOST, $port);

            $browser->get($url);

            Loop::addTimer(0.1, static function () use ($runner): void {
                $runner->stop();
            });
        });

        self::addSafetyTimeout(4.0);
        $runner->run(self::HOST . ':' . $port);

        $this->assertContains('handler:complete', EventTrackingSlowHandler::$events);
        $this->assertTrue($shutdownFired, 'Service shutdown hook should have fired');
    }

    #[Test]
    public function new_connections_refused_after_shutdown(): void
    {
        $port = self::findFreePort();
        $connectionRefused = false;

        $app = Application::starting()->compile();
        $runner = Runner::from($app, requestTimeout: 5.0, drainTimeout: 0.5);
        $runner->withRoutes(RouteGroup::of([
            'GET /health' => StatusOk::class,
        ]));

        Loop::futureTick(static function () use ($runner, $port, &$connectionRefused): void {
            $runner->stop();

            Loop::addTimer(0.01, static function () use ($port, &$connectionRefused): void {
                $browser = new Browser();
                $url = sprintf('http://%s:%d/health', self::HOST, $port);

                $browser->get($url)->then(
                    static function (): void {},
                    static function () use (&$connectionRefused): void {
                        $connectionRefused = true;
                    },
                );
            });
        });

        self::addSafetyTimeout(3.0);
        $runner->run(self::HOST . ':' . $port);

        $this->assertTrue($connectionRefused, 'Connection should be refused after shutdown');
    }

    private static function findFreePort(): int
    {
        $socket = new SocketServer(self::HOST . ':0');
        $address = $socket->getAddress();
        assert($address !== null);
        $port = (int) parse_url($address, PHP_URL_PORT);
        $socket->close();

        return $port;
    }

    private static function addSafetyTimeout(float $seconds): void
    {
        Loop::addTimer($seconds, static function (): void {
            Loop::stop();
        });
    }
}
