<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Invocation;

use LogicException;
use Phalanx\Invocation\Attempt;
use Phalanx\Invocation\Ctx;
use Phalanx\Invocation\Executable;
use Phalanx\Invocation\InvocationId;
use Phalanx\Mark\Mark;
use Phalanx\Scope\Backoff;
use Phalanx\Scope\Scope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class CtxTest extends TestCase
{
    #[Test]
    public function ctxProjectsScopeStateWithoutExposingTheScope(): void
    {
        $scope = $this->scopeStub(cancelled: false, remaining: Mark::s(5));
        $ctx = $this->ctx($scope);

        self::assertFalse($ctx->cancelled);
        self::assertSame(5_000_000_000, $ctx->remaining->toNanoseconds());
        self::assertSame('run-1', $ctx->id->value);
        self::assertTrue($ctx->attempt->isFirst());
    }

    #[Test]
    public function projectionsReadLiveScopeState(): void
    {
        $scope = $this->scopeStub(cancelled: false, remaining: Mark::s(5));
        $ctx = $this->ctx($scope);

        $scope->cancel();

        self::assertTrue($ctx->cancelled);
    }

    #[Test]
    public function projectionHooksAreSealedAgainstOverride(): void
    {
        foreach (['cancelled', 'remaining'] as $projection) {
            $property = new ReflectionProperty(Ctx::class, $projection);

            self::assertTrue($property->isFinal(), "Ctx::\${$projection} must be sealed final (PV2-A.04).");
            self::assertTrue($property->isVirtual(), "Ctx::\${$projection} must derive, not store.");
        }
    }

    #[Test]
    public function theScopeReferenceIsFullyPrivate(): void
    {
        $property = new ReflectionProperty(Ctx::class, 'scope');

        self::assertTrue($property->isPrivate(), 'Ctx must never expose the scope reference (B7).');
    }

    private function ctx(Scope $scope): Ctx
    {
        return new class (InvocationId::of('run-1'), Attempt::first(), $scope) extends Ctx {
        };
    }

    private function scopeStub(bool $cancelled, Mark $remaining): Scope
    {
        return new class ($cancelled, $remaining) implements Scope {
            public function __construct(
                private bool $cancelled,
                private readonly Mark $budget,
            ) {
            }

            public function run(Executable $work): mixed
            {
                throw new LogicException('not dispatched in this stub');
            }

            public function parallel(array $work): array
            {
                return [];
            }

            public function map(iterable $items, callable $factory): array
            {
                return [];
            }

            public function race(array $work): mixed
            {
                throw new LogicException('not dispatched in this stub');
            }

            public function series(Executable $first, callable ...$steps): mixed
            {
                throw new LogicException('not dispatched in this stub');
            }

            public function onErr(callable $compensation): void
            {
            }

            public function cancel(): void
            {
                $this->cancelled = true;
            }

            public function isCancelled(): bool
            {
                return $this->cancelled;
            }

            public function remaining(): Mark
            {
                return $this->budget;
            }

            public function withRetry(int $attempts, Backoff $backoff): Scope
            {
                return $this;
            }

            public function withDeadline(Mark $deadline): Scope
            {
                return $this;
            }

            public function withoutRetry(): Scope
            {
                return $this;
            }

            public function faultsAs(array|callable $absorb): Scope
            {
                return $this;
            }
        };
    }
}
