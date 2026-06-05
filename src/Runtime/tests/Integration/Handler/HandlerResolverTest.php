<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Integration\Handler;

use Phalanx\Handler\HandlerDependencyNotResolvable;
use Phalanx\Handler\HandlerResolver;
use Phalanx\Runtime\Tests\Fixtures\Handlers\HandlerA;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Testing\TestApp;
use PHPUnit\Framework\Attributes\Test;

final class HandlerResolverTest extends PhalanxTestCase
{
    private TestApp $testApp;

    #[Test]
    public function resolves_handler_with_no_constructor(): void
    {
        $scope = $this->testApp->application->createScope();
        $resolver = $scope->service(HandlerResolver::class);

        $instance = $resolver->resolve(HandlerA::class, $scope);

        $this->assertInstanceOf(HandlerA::class, $instance);
    }

    #[Test]
    public function rejects_scalar_constructor_parameter(): void
    {
        $scope = $this->testApp->application->createScope();
        $resolver = $scope->service(HandlerResolver::class);

        $this->expectException(HandlerDependencyNotResolvable::class);
        $this->expectExceptionMessage('scalar/builtin parameters are not allowed');

        $resolver->resolve(ScalarParamHandler::class, $scope);
    }

    #[Test]
    public function nullable_unresolved_dependency_falls_back_to_null(): void
    {
        $scope = $this->testApp->application->createScope();
        $resolver = $scope->service(HandlerResolver::class);

        $instance = $resolver->resolve(NullableDepHandler::class, $scope);

        $this->assertInstanceOf(NullableDepHandler::class, $instance);
        $this->assertNull($instance->dep);
    }

    protected function setUp(): void
    {
        $this->testApp = $this->testApp();
    }
}

final class ScalarParamHandler
{
    public function __construct(public readonly int $count)
    {
    }
}

final class NullableDepHandler
{
    public function __construct(public readonly ?\stdClass $dep = null)
    {
    }
}
