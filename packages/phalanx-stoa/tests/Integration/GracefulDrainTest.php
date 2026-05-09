<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Runtime\Identity\StoaEventSid;
use Phalanx\Stoa\StoaRunner;
use Phalanx\Stoa\StoaServerConfig;
use Phalanx\Styx\Channel;
use Phalanx\Task\Scopeable;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Tests\Stoa\Fixtures\Routes\StatusOk;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

final class GracefulDrainTest extends PhalanxTestCase
{
    #[Test]
    public function inflightRequestCompletesWithinDrainTimeout(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            DrainCompletingHandler::$entered = new Channel();
            $app = Application::starting()->compile()->startup();
            $runner = StoaRunner::from($app, new StoaServerConfig(requestTimeout: 5.0, drainTimeout: 2.0))
                ->withRoutes(RouteGroup::of([
                    'GET /slow' => DrainCompletingHandler::class,
                ]));

            $results = $scope->concurrent(
                static fn(): ResponseInterface => $runner->dispatch(new ServerRequest('GET', '/slow')),
                static function (ExecutionScope $control) use ($runner): null {
                    self::readSignal(DrainCompletingHandler::$entered);
                    self::assertSame(1, $runner->activeRequests());
                    $runner->stop();

                    return null;
                },
            );

            $response = $results[0];
            self::assertInstanceOf(ResponseInterface::class, $response);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('completed', (string) $response->getBody());
            self::assertSame(0, $runner->activeRequests());
        });
    }

    #[Test]
    public function drainTimeoutCancelsStuckRequest(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            DrainStuckHandler::$cancelled = false;
            DrainStuckHandler::$resourceId = '';
            DrainStuckHandler::$entered = new Channel();
            $app = Application::starting()->compile()->startup();
            $events = [];
            $app->runtime()->memory->events->listen(static function ($event) use (&$events): void {
                $events[] = $event;
            });
            $runner = StoaRunner::from($app, new StoaServerConfig(requestTimeout: 10.0, drainTimeout: 0.05))
                ->withRoutes(RouteGroup::of([
                    'GET /stuck' => DrainStuckHandler::class,
                ]));

            $start = hrtime(true);
            $results = $scope->settle(
                request: static fn(): ResponseInterface => $runner->dispatch(new ServerRequest('GET', '/stuck')),
                control: static function (ExecutionScope $control) use ($runner, &$start): null {
                    self::readSignal(DrainStuckHandler::$entered);
                    self::assertSame(1, $runner->activeRequests());
                    $start = hrtime(true);
                    $runner->stop();

                    return null;
                },
            );

            $elapsed = (hrtime(true) - $start) / 1e9;

            self::assertTrue($results->isOk('control'));
            if ($results->isOk('request')) {
                $response = $results->get('request');
                self::assertInstanceOf(ResponseInterface::class, $response);
                self::assertSame(500, $response->getStatusCode());
            } else {
                self::assertInstanceOf(Cancelled::class, $results->errors['request']);
            }
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
        $this->scope->run(static function (ExecutionScope $scope): void {
            DrainCompletingHandler::$entered = new Channel();
            $app = Application::starting()->compile()->startup();
            $runner = StoaRunner::from($app, new StoaServerConfig(requestTimeout: 5.0, drainTimeout: 2.0))
                ->withRoutes(RouteGroup::of([
                    'GET /slow' => DrainCompletingHandler::class,
                    'GET /health' => StatusOk::class,
                ]));

            $results = $scope->concurrent(
                static fn(): ResponseInterface => $runner->dispatch(new ServerRequest('GET', '/slow')),
                static function (ExecutionScope $control) use ($runner): ResponseInterface {
                    self::readSignal(DrainCompletingHandler::$entered);
                    $runner->stop();

                    return $runner->dispatch(new ServerRequest('GET', '/health'));
                },
            );

            $completed = $results[0];
            $rejected = $results[1];
            self::assertInstanceOf(ResponseInterface::class, $completed);
            self::assertInstanceOf(ResponseInterface::class, $rejected);
            self::assertSame(503, $rejected->getStatusCode());
            self::assertSame(200, $completed->getStatusCode());
        });
    }

    #[Test]
    public function serviceShutdownHooksFireAfterDrain(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $shutdownFired = false;
            $bundle = new class ($shutdownFired) extends ServiceBundle {
                public function __construct(private bool &$shutdownFired)
                {
                }

                public function services(Services $services, AppContext $context): void
                {
                    $fired = &$this->shutdownFired;
                    $services->eager(\stdClass::class)
                        ->factory(static fn(): \stdClass => new \stdClass())
                        ->onShutdown(static function () use (&$fired): void {
                            $fired = true;
                        });
                }
            };

            DrainEventTrackingHandler::$entered = new Channel();
            DrainEventTrackingHandler::$events = [];

            $app = Application::starting()->providers($bundle)->compile()->startup();
            $runner = StoaRunner::from($app, new StoaServerConfig(requestTimeout: 5.0, drainTimeout: 2.0))
                ->withRoutes(RouteGroup::of([
                    'GET /slow' => DrainEventTrackingHandler::class,
                ]));

            $scope->concurrent(
                static fn(): ResponseInterface => $runner->dispatch(new ServerRequest('GET', '/slow')),
                static function (ExecutionScope $control) use ($runner): null {
                    self::readSignal(DrainEventTrackingHandler::$entered);
                    $runner->stop();

                    return null;
                },
            );

            self::assertContains('handler:complete', DrainEventTrackingHandler::$events);
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

    private static function readSignal(Channel $channel): mixed
    {
        foreach ($channel->consume() as $value) {
            return $value;
        }

        self::fail('Expected drain signal.');
    }
}

final class DrainCompletingHandler implements Scopeable
{
    public static Channel $entered;

    public function __invoke(RequestScope $scope): string
    {
        self::$entered->emit(true);
        $scope->delay(0.3);

        return 'completed';
    }
}

final class DrainEventTrackingHandler implements Scopeable
{
    public static Channel $entered;

    /** @var list<string> */
    public static array $events = [];

    public function __invoke(RequestScope $scope): string
    {
        self::$entered->emit(true);
        $scope->delay(0.3);
        self::$events[] = 'handler:complete';

        return 'done';
    }
}

final class DrainStuckHandler implements Scopeable
{
    public static bool $cancelled = false;
    public static Channel $entered;
    public static string $resourceId = '';

    public function __invoke(RequestScope $scope): string
    {
        self::$resourceId = $scope->resourceId;
        self::$entered->emit(true);

        try {
            $scope->delay(1.5);
        } catch (Cancelled $e) {
            self::$cancelled = true;
            throw $e;
        }

        return 'completed';
    }
}
