<?php

declare(strict_types=1);

namespace Convoy\Tests\Integration\Handler;

use Convoy\Application;
use Convoy\ExecutionScope;
use Convoy\Handler\Handler;
use Convoy\Handler\HandlerGroup;
use Convoy\Handler\HandlerMatcher;
use Convoy\Handler\MatchResult;
use Convoy\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandlerDispatchTest extends TestCase
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
    public function dispatches_by_handler_key(): void
    {
        $group = HandlerGroup::of([
            'task-a' => Handler::of(Task::of(static fn() => 'a')),
            'task-b' => Handler::of(Task::of(static fn() => 'b')),
        ]);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('handler.key', 'task-b');

        $result = $scope->execute($group);

        $this->assertSame('b', $result);
    }

    #[Test]
    public function throws_when_handler_key_not_found(): void
    {
        $group = HandlerGroup::of([
            'task-a' => Handler::of(Task::of(static fn() => 'a')),
        ]);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('handler.key', 'nonexistent');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Handler not found: nonexistent');

        $scope->execute($group);
    }

    #[Test]
    public function dispatches_via_registered_matcher(): void
    {
        $matcher = new class implements HandlerMatcher {
            public function match(ExecutionScope $scope, array $handlers): ?MatchResult
            {
                $target = $scope->attribute('custom.target');
                if ($target === null) {
                    return null;
                }

                $handler = $handlers[$target] ?? null;
                if ($handler === null) {
                    return null;
                }

                return new MatchResult($handler, $scope);
            }
        };

        $group = HandlerGroup::of([
            'task-a' => Handler::of(Task::of(static fn() => 'a')),
            'task-b' => Handler::of(Task::of(static fn() => 'b')),
        ])->withMatcher($matcher);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('custom.target', 'task-b');

        $result = $scope->execute($group);

        $this->assertSame('b', $result);
    }

    #[Test]
    public function throws_when_no_matcher_handles_scope(): void
    {
        $group = HandlerGroup::of([
            'task-a' => Handler::of(Task::of(static fn() => 'a')),
        ]);

        $scope = $this->app->createScope();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no matcher could handle this scope');

        $scope->execute($group);
    }

    #[Test]
    public function applies_group_middleware_with_key_dispatch(): void
    {
        $calls = [];

        $middleware = Task::of(static function (ExecutionScope $es) use (&$calls): mixed {
            $calls[] = 'middleware:before';
            $next = $es->attribute('handler.next');
            $result = $es->execute($next);
            $calls[] = 'middleware:after';
            return $result;
        });

        $group = HandlerGroup::of([
            'task-a' => Handler::of(Task::of(static function () use (&$calls): string {
                $calls[] = 'handler';
                return 'done';
            })),
        ])->wrap($middleware);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('handler.key', 'task-a');

        $result = $scope->execute($group);

        $this->assertSame('done', $result);
        $this->assertSame(['middleware:before', 'handler', 'middleware:after'], $calls);
    }
}
