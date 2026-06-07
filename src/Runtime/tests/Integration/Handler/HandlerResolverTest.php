<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Integration\Handler;

use Phalanx\Handler\HandlerDependencyNotResolvable;
use Phalanx\Handler\HandlerResolver;
use Phalanx\Runtime\Tests\Fixtures\Handlers\HandlerA;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Testing\TestApp;
use PHPUnit\Framework\Attributes\Test;

final class HandlerResolverTest extends PhalanxTestCase
{
    private TestApp $testApp;

    #[Test]
    public function resolves_handler_with_no_constructor(): void
    {
        $instance = $this->testApp->application->scoped(static function (ExecutionScope $scope): object {
            $resolver = $scope->service(HandlerResolver::class);

            return $resolver->resolve($scope, HandlerA::class);
        });

        $this->assertInstanceOf(HandlerA::class, $instance);
    }

    #[Test]
    public function rejects_scalar_constructor_parameter(): void
    {
        $this->expectException(HandlerDependencyNotResolvable::class);
        $this->expectExceptionMessage('scalar/builtin parameters are not allowed');

        $this->testApp->application->scoped(static function (ExecutionScope $scope): object {
            $resolver = $scope->service(HandlerResolver::class);

            return $resolver->resolve($scope, ScalarParamHandler::class);
        });
    }

    #[Test]
    public function nullable_unresolved_dependency_falls_back_to_null(): void
    {
        $instance = $this->testApp->application->scoped(static function (ExecutionScope $scope): object {
            $resolver = $scope->service(HandlerResolver::class);

            return $resolver->resolve($scope, NullableDepHandler::class);
        });

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
