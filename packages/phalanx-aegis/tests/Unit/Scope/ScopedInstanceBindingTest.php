<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScopedInstanceBindingTest extends TestCase
{
    #[Test]
    public function bound_scoped_instance_resolves_without_a_service_registration(): void
    {
        $app = Application::starting()->compile()->startup();
        $scope = $app->createScope();
        self::assertInstanceOf(ExecutionLifecycleScope::class, $scope);

        try {
            $instance = new BoundRequestState('req-1');
            $scope->bindScopedInstance(BoundRequestState::class, $instance);

            self::assertSame($instance, $scope->service(BoundRequestState::class));
        } finally {
            $scope->dispose();
            $app->shutdown();
        }
    }

    #[Test]
    public function bound_scoped_instance_is_isolated_per_scope_and_disposed_with_service_hooks(): void
    {
        $disposed = [];
        $app = Application::starting()
            ->providers(new BoundRequestStateBundle($disposed))
            ->compile()
            ->startup();

        $first = $app->createScope();
        $second = $app->createScope();
        self::assertInstanceOf(ExecutionLifecycleScope::class, $first);
        self::assertInstanceOf(ExecutionLifecycleScope::class, $second);

        try {
            $firstState = new BoundRequestState('first');
            $secondState = new BoundRequestState('second');

            $first->bindScopedInstance(BoundRequestState::class, $firstState);
            $second->bindScopedInstance(BoundRequestState::class, $secondState);

            self::assertSame($firstState, $first->service(BoundRequestState::class));
            self::assertSame($secondState, $second->service(BoundRequestState::class));
        } finally {
            $first->dispose();
            $second->dispose();
            $app->shutdown();
        }

        self::assertSame(['first', 'second'], $disposed);
    }
}

final readonly class BoundRequestState
{
    public function __construct(public string $id)
    {
    }
}

final class BoundRequestStateBundle extends ServiceBundle
{
    /** @param list<string> $disposed */
    public function __construct(private array &$disposed)
    {
    }

    public function services(Services $services, AppContext $context): void
    {
        $services->scoped(BoundRequestState::class)
            ->factory(static fn(): BoundRequestState => new BoundRequestState('factory'))
            ->onDispose(function (BoundRequestState $state): void {
                $this->disposed[] = $state->id;
            });
    }
}
