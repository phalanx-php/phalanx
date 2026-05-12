<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;

final class ScopeResourceTest extends PhalanxTestCase
{
    public function testSetResourceVisibleOnSameScope(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            self::assertInstanceOf(ExecutionLifecycleScope::class, $scope);
            $scope->setResource('db.pool', 'pg-pool-instance');

            self::assertSame('pg-pool-instance', $scope->resource('db.pool'));
        });
    }

    public function testResourceReturnsDefaultWhenNotSet(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            self::assertNull($scope->resource('missing'));
            self::assertSame('fallback', $scope->resource('missing', 'fallback'));
        });
    }

    public function testResourcesSharedThroughWithAttribute(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            self::assertInstanceOf(ExecutionLifecycleScope::class, $scope);
            $scope->setResource('request', 'psr7-request-object');

            $child = $scope->withAttribute('tenant.id', 'acme');

            self::assertSame('psr7-request-object', $child->resource('request'));
        });
    }

    public function testResourcesSharedThroughConcurrentChildren(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            self::assertInstanceOf(ExecutionLifecycleScope::class, $scope);
            $scope->setResource('http.client', 'iris-client');

            $observed = $scope->concurrent(
                a: Task::of(static fn(ExecutionScope $s) => $s->resource('http.client')),
                b: Task::of(static fn(ExecutionScope $s) => $s->resource('http.client')),
            );

            self::assertSame(['a' => 'iris-client', 'b' => 'iris-client'], $observed);
        });
    }

    public function testResourcesSharedThroughExecuteFresh(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            self::assertInstanceOf(ExecutionLifecycleScope::class, $scope);
            $scope->setResource('supervisor', 'supervisor-instance');

            $observed = $scope->executeFresh(
                Task::of(static fn(ExecutionScope $s) => $s->resource('supervisor')),
            );

            self::assertSame('supervisor-instance', $observed);
        });
    }

    public function testResourceMutationVisibleAcrossTree(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            self::assertInstanceOf(ExecutionLifecycleScope::class, $scope);
            $scope->setResource('counter', 0);

            $child = $scope->withAttribute('label', 'child');

            $scope->setResource('counter', 42);

            self::assertSame(42, $child->resource('counter'));
        });
    }

    public function testChildResourceMutationVisibleOnParent(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            self::assertInstanceOf(ExecutionLifecycleScope::class, $scope);
            $scope->setResource('counter', 0);

            $child = $scope->withAttribute('label', 'child');
            self::assertInstanceOf(ExecutionLifecycleScope::class, $child);
            $child->setResource('counter', 99);

            self::assertSame(99, $scope->resource('counter'));
        });
    }

    public function testResourcesIndependentFromAttributes(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            self::assertInstanceOf(ExecutionLifecycleScope::class, $scope);
            $scope->setResource('request', 'resource-value');
            $child = $scope->withAttribute('request', 'attribute-value');

            self::assertSame('resource-value', $child->resource('request'));
            self::assertSame('attribute-value', $child->attribute('request'));
        });
    }

    public function testResourcesVisibleThroughExecutionContextDecorator(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $inner = self::buildScope();
            self::assertInstanceOf(ExecutionLifecycleScope::class, $inner);
            $inner->setResource('stoa.resource', 'request-resource-obj');

            $observed = $inner->execute(
                Task::of(static fn(ExecutionScope $s) => $s->resource('stoa.resource')),
            );

            self::assertSame('request-resource-obj', $observed);
            $inner->dispose();
        });
    }

    private static function buildScope(): ExecutionScope
    {
        $bundle = new class extends ServiceBundle {
            public function services(Services $services, AppContext $context): void
            {
            }
        };
        $app = Application::starting()
            ->providers($bundle)
            ->withLedger(new InProcessLedger())
            ->compile();
        return $app->createScope();
    }
}
