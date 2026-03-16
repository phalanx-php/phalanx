<?php

declare(strict_types=1);

namespace Convoy\Tests\Smoke;

use Convoy\Application;
use Convoy\ExecutionScope;
use Convoy\Scope;
use Convoy\Service\ServiceBundle;
use Convoy\Service\Services;
use Convoy\Task\Scopeable;
use Convoy\Task\Task;
use Convoy\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;

final class FullApplicationLifecycleTest extends AsyncTestCase
{
    #[Test]
    public function full_lifecycle_with_services_and_tasks(): void
    {
        $events = [];

        $bundle = new class($events) implements ServiceBundle {
            public function __construct(private array &$events) {}

            public function services(Services $services, array $context): void
            {
                $events = &$this->events;

                $services->singleton(DatabaseConnection::class)
                    ->factory(static function () use (&$events) {
                        $events[] = 'db:create';
                        return new DatabaseConnection();
                    })
                    ->onInit(static function () use (&$events) {
                        $events[] = 'db:init';
                    })
                    ->onStartup(static function (DatabaseConnection $db) use (&$events) {
                        $events[] = 'db:startup';
                        $db->connect();
                    })
                    ->onShutdown(static function (DatabaseConnection $db) use (&$events) {
                        $events[] = 'db:shutdown';
                        $db->disconnect();
                    });

                $services->scoped(RequestContext::class)
                    ->factory(static function () use (&$events) {
                        $events[] = 'request:create';
                        return new RequestContext();
                    })
                    ->onDispose(static function () use (&$events) {
                        $events[] = 'request:dispose';
                    });

                $services->eager(MetricsCollector::class)
                    ->factory(static function () use (&$events) {
                        $events[] = 'metrics:create';
                        return new MetricsCollector();
                    })
                    ->onStartup(static function () use (&$events) {
                        $events[] = 'metrics:startup';
                    })
                    ->onShutdown(static function () use (&$events) {
                        $events[] = 'metrics:shutdown';
                    });
            }
        };

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $this->assertNotContains('metrics:create', $events);

        $app->startup();

        $this->assertContains('metrics:create', $events);
        $this->assertContains('metrics:startup', $events);

        $this->runAsync(function () use ($app, &$events): void {
            $scope = $app->createScope();

            $result = $scope->execute(Task::of(static function (ExecutionScope $es) use (&$events) {
                $events[] = 'task:start';

                $es->service(DatabaseConnection::class);
                $events[] = 'task:db_used';

                $es->service(RequestContext::class);
                $events[] = 'task:request_used';

                return 'completed';
            }));

            $this->assertSame('completed', $result);

            $scope->dispose();
        });

        $this->assertContains('db:create', $events);
        $this->assertContains('db:init', $events);
        $this->assertContains('request:create', $events);
        $this->assertContains('task:start', $events);
        $this->assertContains('task:db_used', $events);
        $this->assertContains('task:request_used', $events);
        $this->assertContains('request:dispose', $events);

        $app->shutdown();

        $this->assertContains('db:shutdown', $events);
        $this->assertContains('metrics:shutdown', $events);
    }

    #[Test]
    public function multiple_scopes_share_singletons(): void
    {
        $bundle = new class implements ServiceBundle {
            public function services(Services $services, array $context): void
            {
                $services->singleton(Counter::class)
                    ->factory(static fn() => new Counter());

                $services->scoped(RequestId::class)
                    ->factory(static fn() => new RequestId());
            }
        };

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $app->startup();

        $scope1 = $app->createScope();
        $scope2 = $app->createScope();

        $counter1 = $scope1->service(Counter::class);
        $counter2 = $scope2->service(Counter::class);

        $this->assertSame($counter1, $counter2);

        $requestId1 = $scope1->service(RequestId::class);
        $requestId2 = $scope2->service(RequestId::class);

        $this->assertNotSame($requestId1, $requestId2);

        $scope1->dispose();
        $scope2->dispose();
        $app->shutdown();
    }

    #[Test]
    public function task_can_execute_nested_tasks(): void
    {
        $app = Application::starting()->compile();
        $app->startup();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $outerTask = Task::of(static function (ExecutionScope $es) {
                $innerResult = $es->execute(Task::of(static fn() => 'inner'));
                return "outer:$innerResult";
            });

            $result = $scope->execute($outerTask);

            $this->assertSame('outer:inner', $result);

            $scope->dispose();
        });

        $app->shutdown();
    }

    #[Test]
    public function dispatchable_class_integrates_with_services(): void
    {
        $bundle = new class implements ServiceBundle {
            public function services(Services $services, array $context): void
            {
                $services->singleton(Calculator::class)
                    ->factory(static fn() => new Calculator());
            }
        };

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $app->startup();

        $scope = $app->createScope();

        $task = new AddNumbers(5, 3);
        $result = $scope->execute($task);

        $this->assertSame(8, $result);

        $scope->dispose();
        $app->shutdown();
    }

    #[Test]
    public function scope_attributes_flow_through_task_chain(): void
    {
        $app = Application::starting()->compile();
        $app->startup();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $result = $scope->waterfall([
                Task::of(static fn() => 10),
                Task::of(static fn(ExecutionScope $es) => $es->attribute('_waterfall_previous') + 5),
                Task::of(static fn(ExecutionScope $es) => $es->attribute('_waterfall_previous') * 2),
            ]);

            $this->assertSame(30, $result);

            $scope->dispose();
        });

        $app->shutdown();
    }
}

class DatabaseConnection
{
    public bool $connected = false;

    public function connect(): void
    {
        $this->connected = true;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }
}

class RequestContext
{
    public readonly string $id;

    public function __construct()
    {
        $this->id = uniqid('req_');
    }
}

class MetricsCollector
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }
}

class Counter
{
    public int $value = 0;

    public function increment(): int
    {
        return ++$this->value;
    }
}

class RequestId
{
    public readonly string $id;

    public function __construct()
    {
        $this->id = uniqid();
    }
}

class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}

class AddNumbers implements Scopeable
{
    public function __construct(
        private readonly int $a,
        private readonly int $b,
    ) {}

    public function __invoke(Scope $scope): int
    {
        $calc = $scope->service(Calculator::class);
        return $calc->add($this->a, $this->b);
    }
}
