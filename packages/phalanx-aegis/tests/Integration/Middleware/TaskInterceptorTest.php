<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\Middleware;

use Phalanx\Application;
use Phalanx\Middleware\TaskMiddleware;
use Phalanx\ExecutionScope;
use Phalanx\Scope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;
use Phalanx\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class TaskInterceptorTest extends AsyncTestCase
{
    #[Test]
    public function interceptor_receives_task_and_scope(): void
    {
        $receivedTask = null;
        $receivedScope = null;

        $interceptor = new class($receivedTask, $receivedScope) implements TaskMiddleware {
            public function __construct(
                private mixed &$receivedTask,
                private mixed &$receivedScope,
            ) {
            }

            public function process(Scopeable|Executable $task, ExecutionScope $scope, callable $next): mixed
            {
                $this->receivedTask = $task;
                $this->receivedScope = $scope;
                return $next();
            }
        };

        $app = Application::starting()
            ->taskMiddleware($interceptor)
            ->compile();

        $scope = $app->createScope();
        $task = Task::of(static fn() => 'result');

        $scope->execute($task);

        $this->assertSame($task, $receivedTask);
        $this->assertInstanceOf(Scope::class, $receivedScope);
    }

    #[Test]
    public function interceptor_pipeline_order(): void
    {
        $executionOrder = [];

        $interceptor1 = new class($executionOrder) implements TaskMiddleware {
            public function __construct(private array &$order) {}

            public function process(Scopeable|Executable $task, ExecutionScope $scope, callable $next): mixed
            {
                $this->order[] = 'before_1';
                $result = $next();
                $this->order[] = 'after_1';
                return $result;
            }
        };

        $interceptor2 = new class($executionOrder) implements TaskMiddleware {
            public function __construct(private array &$order) {}

            public function process(Scopeable|Executable $task, ExecutionScope $scope, callable $next): mixed
            {
                $this->order[] = 'before_2';
                $result = $next();
                $this->order[] = 'after_2';
                return $result;
            }
        };

        $app = Application::starting()
            ->taskMiddleware($interceptor1, $interceptor2)
            ->compile();

        $scope = $app->createScope();

        $scope->execute(Task::of(static function () use (&$executionOrder) {
            $executionOrder[] = 'task';
            return 'result';
        }));

        $this->assertEquals(
            ['before_1', 'before_2', 'task', 'after_2', 'after_1'],
            $executionOrder,
        );
    }

    #[Test]
    public function interceptor_can_modify_result(): void
    {
        $interceptor = new class implements TaskMiddleware {
            public function process(Scopeable|Executable $task, ExecutionScope $scope, callable $next): mixed
            {
                $result = $next();
                return $result * 2;
            }
        };

        $app = Application::starting()
            ->taskMiddleware($interceptor)
            ->compile();

        $scope = $app->createScope();

        $result = $scope->execute(Task::of(static fn() => 5));

        $this->assertSame(10, $result);
    }

    #[Test]
    public function interceptor_can_short_circuit(): void
    {
        $taskExecuted = false;

        $interceptor = new class implements TaskMiddleware {
            public function process(Scopeable|Executable $task, ExecutionScope $scope, callable $next): mixed
            {
                return 'short-circuited';
            }
        };

        $app = Application::starting()
            ->taskMiddleware($interceptor)
            ->compile();

        $scope = $app->createScope();

        $result = $scope->execute(Task::of(static function () use (&$taskExecuted) {
            $taskExecuted = true;
            return 'original';
        }));

        $this->assertFalse($taskExecuted);
        $this->assertSame('short-circuited', $result);
    }

    #[Test]
    public function exception_propagates_through_stack(): void
    {
        $interceptor1Caught = false;
        $interceptor2Caught = false;

        $interceptor1 = new class($interceptor1Caught) implements TaskMiddleware {
            public function __construct(private bool &$caught) {}

            public function process(Scopeable|Executable $task, ExecutionScope $scope, callable $next): mixed
            {
                try {
                    return $next();
                } catch (RuntimeException $e) {
                    $this->caught = true;
                    throw $e;
                }
            }
        };

        $interceptor2 = new class($interceptor2Caught) implements TaskMiddleware {
            public function __construct(private bool &$caught) {}

            public function process(Scopeable|Executable $task, ExecutionScope $scope, callable $next): mixed
            {
                try {
                    return $next();
                } catch (RuntimeException $e) {
                    $this->caught = true;
                    throw $e;
                }
            }
        };

        $app = Application::starting()
            ->taskMiddleware($interceptor1, $interceptor2)
            ->compile();

        $scope = $app->createScope();

        try {
            $scope->execute(Task::of(static function () {
                throw new RuntimeException('Task failed');
            }));
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Task failed', $e->getMessage());
        }

        $this->assertTrue($interceptor1Caught);
        $this->assertTrue($interceptor2Caught);
    }

    #[Test]
    public function interceptor_can_catch_and_handle_exception(): void
    {
        $interceptor = new class implements TaskMiddleware {
            public function process(Scopeable|Executable $task, ExecutionScope $scope, callable $next): mixed
            {
                try {
                    return $next();
                } catch (RuntimeException) {
                    return 'recovered';
                }
            }
        };

        $app = Application::starting()
            ->taskMiddleware($interceptor)
            ->compile();

        $scope = $app->createScope();

        $result = $scope->execute(Task::of(static function () {
            throw new RuntimeException('Task failed');
        }));

        $this->assertSame('recovered', $result);
    }

    #[Test]
    public function interceptor_receives_dispatchable_interface(): void
    {
        $taskType = null;

        $interceptor = new class($taskType) implements TaskMiddleware {
            public function __construct(private ?string &$type) {}

            public function process(Scopeable|Executable $task, ExecutionScope $scope, callable $next): mixed
            {
                $this->type = $task::class;
                return $next();
            }
        };

        $app = Application::starting()
            ->taskMiddleware($interceptor)
            ->compile();

        $scope = $app->createScope();

        $scope->execute(Task::of(static fn() => 'result'));
        $this->assertSame(Task::class, $taskType);

        $customTask = new class implements Scopeable {
            public function __invoke(Scope $scope): string
            {
                return 'custom';
            }
        };

        $scope->execute($customTask);
        $this->assertNotSame(Task::class, $taskType);
    }

    #[Test]
    public function multiple_interceptors_can_transform_result(): void
    {
        $addOne = new class implements TaskMiddleware {
            public function process(Scopeable|Executable $task, ExecutionScope $scope, callable $next): mixed
            {
                return $next() + 1;
            }
        };

        $double = new class implements TaskMiddleware {
            public function process(Scopeable|Executable $task, ExecutionScope $scope, callable $next): mixed
            {
                return $next() * 2;
            }
        };

        $app = Application::starting()
            ->taskMiddleware($addOne, $double)
            ->compile();

        $scope = $app->createScope();

        $result = $scope->execute(Task::of(static fn() => 5));

        $this->assertSame(11, $result);
    }

    #[Test]
    public function interceptor_works_with_async_operations(): void
    {
        $this->runAsync(function (): void {
            $timing = [];

            $interceptor = new class($timing) implements TaskMiddleware {
                public function __construct(private array &$timing) {}

                public function process(Scopeable|Executable $task, ExecutionScope $scope, callable $next): mixed
                {
                    $this->timing['before'] = hrtime(true);
                    $result = $next();
                    $this->timing['after'] = hrtime(true);
                    return $result;
                }
            };

            $app = Application::starting()
                ->taskMiddleware($interceptor)
                ->compile();

            $scope = $app->createScope();

            $scope->execute(Task::of(static function (ExecutionScope $es) {
                $es->delay(0.01);
                return 'delayed';
            }));

            $this->assertArrayHasKey('before', $timing);
            $this->assertArrayHasKey('after', $timing);
            $this->assertGreaterThan($timing['before'], $timing['after']);
        });
    }

    #[Test]
    public function no_interceptors_executes_task_directly(): void
    {
        $app = Application::starting()->compile();

        $scope = $app->createScope();

        $result = $scope->execute(Task::of(static fn() => 42));

        $this->assertSame(42, $result);
    }
}
