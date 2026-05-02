<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration;

use GuzzleHttp\Psr7\ServerRequest;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use Phalanx\Application;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Stoa\StoaRunner;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Tests\Stoa\Fixtures\EventTrackingSlowHandler;
use Phalanx\Tests\Stoa\Fixtures\Routes\StatusOk;
use Phalanx\Tests\Stoa\Fixtures\SlowHandler;
use Phalanx\Tests\Support\CoroutineTestCase;
use Phalanx\Task\Scopeable;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

final class GracefulDrainTest extends CoroutineTestCase
{
    #[Test]
    public function inflight_request_completes_within_drain_timeout(): void
    {
        $this->runInCoroutine(function (): void {
            $app = Application::starting()->compile()->startup();
            $runner = StoaRunner::from($app, requestTimeout: 5.0, drainTimeout: 2.0)
                ->withRoutes(RouteGroup::of([
                    'GET /slow' => SlowHandler::class,
                ]));
            $responses = new Channel(1);

            Coroutine::create(static function () use ($runner, $responses): void {
                $responses->push($runner->dispatch(new ServerRequest('GET', '/slow')));
            });

            Coroutine::usleep(50_000);
            $runner->stop();

            $response = $responses->pop(3.0);
            self::assertInstanceOf(ResponseInterface::class, $response);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('completed', (string) $response->getBody());
            self::assertSame(0, $runner->activeRequests());
        });
    }

    #[Test]
    public function drain_timeout_cancels_stuck_request(): void
    {
        $this->runInCoroutine(function (): void {
            $app = Application::starting()->compile()->startup();
            $runner = StoaRunner::from($app, requestTimeout: 10.0, drainTimeout: 0.05)
                ->withRoutes(RouteGroup::of([
                    'GET /stuck' => DrainStuckHandler::class,
                ]));
            $responses = new Channel(1);

            Coroutine::create(static function () use ($runner, $responses): void {
                $responses->push($runner->dispatch(new ServerRequest('GET', '/stuck')));
            });

            Coroutine::usleep(10_000);
            $start = hrtime(true);
            $runner->stop();

            $response = $responses->pop(1.0);
            $elapsed = (hrtime(true) - $start) / 1e9;

            self::assertInstanceOf(ResponseInterface::class, $response);
            self::assertSame(500, $response->getStatusCode());
            self::assertLessThan(0.5, $elapsed, 'Drain timeout should cancel stuck request quickly');
            self::assertSame(0, $runner->activeRequests());
        });
    }

    #[Test]
    public function new_requests_are_rejected_while_draining(): void
    {
        $this->runInCoroutine(function (): void {
            $app = Application::starting()->compile()->startup();
            $runner = StoaRunner::from($app, requestTimeout: 5.0, drainTimeout: 2.0)
                ->withRoutes(RouteGroup::of([
                    'GET /slow' => SlowHandler::class,
                    'GET /health' => StatusOk::class,
                ]));
            $responses = new Channel(1);

            Coroutine::create(static function () use ($runner, $responses): void {
                $responses->push($runner->dispatch(new ServerRequest('GET', '/slow')));
            });

            Coroutine::usleep(50_000);
            $runner->stop();
            $rejected = $runner->dispatch(new ServerRequest('GET', '/health'));
            $completed = $responses->pop(3.0);

            self::assertSame(503, $rejected->getStatusCode());
            self::assertInstanceOf(ResponseInterface::class, $completed);
            self::assertSame(200, $completed->getStatusCode());
        });
    }

    #[Test]
    public function service_shutdown_hooks_fire_after_drain(): void
    {
        $this->runInCoroutine(function (): void {
            $shutdownFired = false;
            $bundle = new class($shutdownFired) implements ServiceBundle {
                public function __construct(private bool &$shutdownFired)
                {
                }

                public function services(Services $services, array $context): void
                {
                    $fired = &$this->shutdownFired;
                    $services->eager(\stdClass::class)
                        ->factory(static fn(): \stdClass => new \stdClass())
                        ->onShutdown(static function () use (&$fired): void {
                            $fired = true;
                        });
                }
            };

            EventTrackingSlowHandler::$events = [];

            $app = Application::starting()->providers($bundle)->compile()->startup();
            $runner = StoaRunner::from($app, requestTimeout: 5.0, drainTimeout: 2.0)
                ->withRoutes(RouteGroup::of([
                    'GET /slow' => EventTrackingSlowHandler::class,
                ]));
            $responses = new Channel(1);

            Coroutine::create(static function () use ($runner, $responses): void {
                $responses->push($runner->dispatch(new ServerRequest('GET', '/slow')));
            });

            Coroutine::usleep(50_000);
            $runner->stop();
            $responses->pop(3.0);
            Coroutine::usleep(100_000);

            self::assertContains('handler:complete', EventTrackingSlowHandler::$events);
            self::assertTrue($shutdownFired, 'Service shutdown hook should have fired');
        });
    }
}

final class DrainStuckHandler implements Scopeable
{
    public function __invoke(RequestScope $scope): string
    {
        $scope->delay(1.5);

        return 'completed';
    }
}
