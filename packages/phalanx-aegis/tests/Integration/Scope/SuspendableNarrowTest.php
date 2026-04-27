<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\Scope;

use Phalanx\Application;
use Phalanx\Concurrency\CancellationToken;
use Phalanx\Exception\CancelledException;
use Phalanx\ExecutionScope;
use Phalanx\Suspendable;
use Phalanx\Task\Task;
use Phalanx\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Verifies the Suspendable interface contract when used as a narrow type-hint.
 *
 * The decomposition of ExecutionScope into granular interfaces means services
 * that only need await() should type-hint Suspendable, not ExecutionScope.
 * Daemon8Bridge is a real example of this pattern. These tests confirm that
 * the cancellation-racing invariant of await() is preserved when the caller
 * only holds a Suspendable reference.
 */
final class SuspendableNarrowTest extends AsyncTestCase
{
    #[Test]
    public function await_resolves_via_narrow_suspendable_type(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $result = $scope->execute(Task::of(static fn(ExecutionScope $scope): mixed => self::awaitOn($scope, resolve('narrow-value'))));

            $this->assertSame('narrow-value', $result);
        });
    }

    #[Test]
    public function await_on_narrow_type_races_scope_cancellation(): void
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

            $scope->execute(Task::of(static fn(ExecutionScope $scope): mixed => self::awaitOn($scope, $deferred->promise())));
        });
    }

    #[Test]
    public function await_on_narrow_type_throws_for_precancelled_scope(): void
    {
        $this->runAsync(function (): void {
            $token = CancellationToken::create();
            $token->cancel();

            $app = Application::starting()->compile();
            $scope = $app->createScope(token: $token);

            $this->expectException(CancelledException::class);

            $scope->execute(Task::of(static fn(ExecutionScope $scope): mixed => self::awaitOn($scope, resolve('never-reached'))));
        });
    }

    #[Test]
    public function await_propagates_rejection_via_narrow_type(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('narrow-rejection');

            $scope->execute(Task::of(static fn(ExecutionScope $scope): mixed => self::awaitOn($scope, \React\Promise\reject(new \RuntimeException('narrow-rejection')))));
        });
    }

    /**
     * Simulates a service that type-hints only Suspendable (e.g. Daemon8Bridge).
     * The narrow type-hint is the point: callers can pass any ExecutionScope here.
     */
    private static function awaitOn(Suspendable $scope, PromiseInterface $promise): mixed
    {
        return $scope->await($promise);
    }
}
