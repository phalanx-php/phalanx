<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\Scope;

use Phalanx\Application;
use Phalanx\Concurrency\CancellationToken;
use Phalanx\Exception\CancelledException;
use Phalanx\ExecutionScope;
use Phalanx\Service\DeferredScope;
use Phalanx\Service\FiberScopeRegistry;
use Phalanx\Task\Task;
use Phalanx\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;
use React\EventLoop\Loop;
use React\Promise\Deferred;

use function React\Promise\resolve;
use function React\Promise\reject;

final class ScopeAwaitTest extends AsyncTestCase
{
    #[Test]
    public function await_resolves_promise(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $result = $scope->execute(Task::of(static fn(ExecutionScope $scope): mixed => $scope->await(resolve('hello'))));

            $this->assertSame('hello', $result);
        });
    }

    #[Test]
    public function await_propagates_promise_rejection(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('boom');

            $scope->execute(Task::of(static fn(ExecutionScope $scope): mixed => $scope->await(reject(new \RuntimeException('boom')))));
        });
    }

    #[Test]
    public function await_throws_on_precancelled_scope(): void
    {
        $this->runAsync(function (): void {
            $token = CancellationToken::create();
            $token->cancel();

            $app = Application::starting()->compile();
            $scope = $app->createScope(token: $token);

            $this->expectException(CancelledException::class);

            $scope->execute(Task::of(static fn(ExecutionScope $scope): mixed => $scope->await(resolve('should not reach'))));
        });
    }

    #[Test]
    public function await_interrupts_on_cancellation(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $token = CancellationToken::create();
            $scope = $app->createScope(token: $token);

            $deferred = new Deferred();

            Loop::addTimer(0.01, static function () use ($token): void {
                $token->cancel();
            });

            $this->expectException(CancelledException::class);

            $scope->execute(Task::of(static fn(ExecutionScope $scope): mixed => $scope->await($deferred->promise())));
        });
    }

    #[Test]
    public function deferred_scope_await_delegates_to_fiber_scope(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $result = $scope->execute(Task::of(static function (ExecutionScope $scope): mixed {
                $deferred = new DeferredScope();
                return $deferred->await(resolve(42));
            }));

            $this->assertSame(42, $result);
        });
    }
}
