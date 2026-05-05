<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration;

use GuzzleHttp\Psr7\ServerRequest;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use Phalanx\Application;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Runtime\Identity\StoaEventSid;
use Phalanx\Stoa\StoaRunner;
use Phalanx\Stoa\StoaServerConfig;
use Phalanx\Task\Scopeable;
use Phalanx\Tests\Stoa\Fixtures\EventTrackingSlowHandler;
use Phalanx\Tests\Stoa\Fixtures\Routes\StatusOk;
use Phalanx\Tests\Stoa\Fixtures\SlowHandler;
use Phalanx\Tests\Support\CoroutineTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

final class GracefulDrainTest extends CoroutineTestCase
{
    #[Test]
    public function inflightRequestCompletesWithinDrainTimeout(): void
    {
        $this->runInCoroutine(static function (): void {
            $app = Application::starting()->compile()->startup();
            $runner = StoaRunner::from($app, new StoaServerConfig(requestTimeout: 5.0, drainTimeout: 2.0))
                ->withRoutes(RouteGroup::of([
                    'GET /slow' => SlowHandler::class,
                ]));
            $responses = new Channel(1);

            Coroutine::create(static function () use ($runner, $responses): void {
                $responses->push($runner->dispatch(new ServerRequest('GET', '/slow')));
            });

            Coroutine::usleep(50_000);
            self::assertSame(1, $runner->activeRequests());
            $runner->stop();

            $response = $responses->pop(3.0);
            self::assertInstanceOf(ResponseInterface::class, $response);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('completed', (string) $response->getBody());
            self::assertSame(0, $runner->activeRequests());
        });
    }

    #[Test]
    public function drainTimeoutCancelsStuckRequest(): void
    {
        $this->runInCoroutine(static function (): void {
            DrainStuckHandler::$cancelled = false;
            DrainStuckHandler::$resourceId = '';
            $app = Application::starting()->compile()->startup();
            $events = [];
            $app->runtime()->memory->events->listen(static function ($event) use (&$events): void {
                $events[] = $event;
            });
            $runner = StoaRunner::from($app, new StoaServerConfig(requestTimeout: 10.0, drainTimeout: 0.05))
                ->withRoutes(RouteGroup::of([
                    'GET /stuck' => DrainStuckHandler::class,
                ]));
            $responses = new Channel(1);

            Coroutine::create(static function () use ($runner, $responses): void {
                $responses->push($runner->dispatch(new ServerRequest('GET', '/stuck')));
            });

            Coroutine::usleep(10_000);
            self::assertSame(1, $runner->activeRequests());
            $start = hrtime(true);
            $runner->stop();

            $response = $responses->pop(1.0);
            $elapsed = (hrtime(true) - $start) / 1e9;

            self::assertInstanceOf(ResponseInterface::class, $response);
            self::assertSame(500, $response->getStatusCode());
            self::assertLessThan(0.5, $elapsed, 'Drain timeout should cancel stuck request quickly');
            self::assertTrue(DrainStuckHandler::$cancelled);
            self::assertNotSame('', DrainStuckHandler::$resourceId);
            self::assertContains(
                StoaEventSid::RequestAborted->value(),
                self::eventTypesForResource($events, DrainStuckHandler::$resourceId),
            );
            self::assertNotContains(
                StoaEventSid::RequestFailed->value(),
                self::eventTypesForResource($events, DrainStuckHandler::$resourceId),
            );
            self::assertSame(0, $runner->activeRequests());
        });
    }

    #[Test]
    public function newRequestsAreRejectedWhileDraining(): void
    {
        $this->runInCoroutine(static function (): void {
            $app = Application::starting()->compile()->startup();
            $runner = StoaRunner::from($app, new StoaServerConfig(requestTimeout: 5.0, drainTimeout: 2.0))
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
    public function serviceShutdownHooksFireAfterDrain(): void
    {
        $this->runInCoroutine(static function (): void {
            $shutdownFired = false;
            $bundle = new class ($shutdownFired) implements ServiceBundle {
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
            $runner = StoaRunner::from($app, new StoaServerConfig(requestTimeout: 5.0, drainTimeout: 2.0))
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

    /**
     * @param list<\Phalanx\Runtime\Memory\RuntimeLifecycleEvent> $events
     * @return list<string>
     */
    private static function eventTypesForResource(array $events, string $resourceId): array
    {
        $types = [];
        foreach ($events as $event) {
            if ($event->resourceId === $resourceId) {
                $types[] = $event->type;
            }
        }

        return $types;
    }
}

final class DrainStuckHandler implements Scopeable
{
    public static bool $cancelled = false;
    public static string $resourceId = '';

    public function __invoke(RequestScope $scope): string
    {
        self::$resourceId = $scope->resourceId;

        try {
            $scope->delay(1.5);
        } catch (Cancelled $e) {
            self::$cancelled = true;
            throw $e;
        }

        return 'completed';
    }
}
