<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use OpenSwoole\Coroutine\Channel;
use Phalanx\Exception\ServiceNotFoundException;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TransactionScope;
use Phalanx\Supervisor\TransactionLease;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;

final class InheritedScopedInstanceTest extends PhalanxTestCase
{
    public function testConcurrentChildrenSeeInheritedScopedInstances(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            self::bindState($scope, new InheritedScopeState('req-7'));

            $observed = $scope->concurrent(
                a: Task::of(static fn(ExecutionScope $s): string => $s->service(InheritedScopeState::class)->value),
                b: Task::of(static fn(ExecutionScope $s): string => $s->service(InheritedScopeState::class)->value),
            );

            self::assertSame(['a' => 'req-7', 'b' => 'req-7'], $observed);
        });
    }

    public function testExecuteFreshSeesInheritedScopedInstances(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            self::bindState($scope, new InheritedScopeState('fresh'));

            $observed = $scope->executeFresh(
                Task::of(static fn(ExecutionScope $s): string => $s->service(InheritedScopeState::class)->value),
            );

            self::assertSame('fresh', $observed);
        });
    }

    public function testGoChildSeesInheritedScopedInstances(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            self::bindState($scope, new InheritedScopeState('go'));
            $seen = new Channel(1);

            $scope->go(static function (ExecutionScope $s) use ($seen): void {
                $seen->push($s->service(InheritedScopeState::class)->value);
            });

            self::assertSame('go', $seen->pop(1.0));
        });
    }

    public function testTransactionScopeSeesInheritedScopedInstances(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            self::bindState($scope, new InheritedScopeState('tx'));

            $observed = $scope->transaction(
                TransactionLease::open('postgres/main', 'tx#ctx'),
                static function (TransactionScope $tx): string {
                    $tx->delay(0.001);

                    return $tx->service(InheritedScopeState::class)->value;
                },
            );

            self::assertSame('tx', $observed);
        });
    }

    public function testInheritedScopedInstancesAreSharedObjects(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $state = new InheritedScopeState('parent');
            self::bindState($scope, $state);

            $scope->executeFresh(Task::of(static function (ExecutionScope $s): void {
                $s->service(InheritedScopeState::class)->value = 'child';
            }));

            self::assertSame('child', $state->value);
        });
    }

    public function testScopedInstancesAreLocalUnlessMarkedInherited(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            self::assertInstanceOf(ExecutionLifecycleScope::class, $scope);
            $scope->bindScopedInstance(InheritedScopeState::class, new InheritedScopeState('local'));

            $thrown = null;
            try {
                $scope->executeFresh(
                    Task::of(
                        static fn(ExecutionScope $s): InheritedScopeState => $s->service(InheritedScopeState::class),
                    ),
                );
            } catch (ServiceNotFoundException $e) {
                $thrown = $e;
            }

            self::assertInstanceOf(ServiceNotFoundException::class, $thrown);
        });
    }

    private static function bindState(ExecutionScope $scope, InheritedScopeState $state): void
    {
        self::assertInstanceOf(ExecutionLifecycleScope::class, $scope);
        $scope->bindScopedInstance(InheritedScopeState::class, $state, inherit: true);
    }
}

class InheritedScopeState
{
    public function __construct(
        public string $value,
    ) {
    }
}
