<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\Handler;

use Phalanx\Application;
use Phalanx\Handler\HandlerDependencyNotResolvable;
use Phalanx\Handler\HandlerResolver;
use Phalanx\Tests\Fixtures\Handlers\HandlerA;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies HandlerResolver behaviors added during the v0.6.0 review pass:
 * generic resolution over any class, nullable parameter fallback to null,
 * default-value parameter fallback, scalar parameter rejection.
 */
final class HandlerResolverTest extends TestCase
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
    public function resolves_handler_with_no_constructor(): void
    {
        $scope = $this->app->createScope();
        $resolver = $scope->service(HandlerResolver::class);

        $instance = $resolver->resolve(HandlerA::class, $scope);

        $this->assertInstanceOf(HandlerA::class, $instance);
    }

    #[Test]
    public function rejects_scalar_constructor_parameter(): void
    {
        $scope = $this->app->createScope();
        $resolver = $scope->service(HandlerResolver::class);

        $this->expectException(HandlerDependencyNotResolvable::class);
        $this->expectExceptionMessage('scalar/builtin parameters are not allowed');

        $resolver->resolve(ScalarParamHandler::class, $scope);
    }

    #[Test]
    public function nullable_unresolved_dependency_falls_back_to_null(): void
    {
        $scope = $this->app->createScope();
        $resolver = $scope->service(HandlerResolver::class);

        $instance = $resolver->resolve(NullableDepHandler::class, $scope);

        $this->assertInstanceOf(NullableDepHandler::class, $instance);
        $this->assertNull($instance->dep);
    }
}

final class ScalarParamHandler
{
    public function __construct(public readonly int $count) {}
}

final class NullableDepHandler
{
    public function __construct(public readonly ?\stdClass $dep = null) {}
}
