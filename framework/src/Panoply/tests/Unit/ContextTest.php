<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Context;
use Phalanx\Panoply\Hash\Canonical;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase
{
    #[Test]
    public function newIsEmpty(): void
    {
        self::assertTrue(Context::new()->isEmpty());
    }

    #[Test]
    public function frontMiddleTailAccumulate(): void
    {
        $ctx = Context::new()
            ->front(ContextFixture\A::class, ContextFixture\B::class)
            ->middle(ContextFixture\C::class)
            ->tail(ContextFixture\D::class, ContextFixture\E::class);

        self::assertSame([ContextFixture\A::class, ContextFixture\B::class], $ctx->frontSources);
        self::assertSame([ContextFixture\C::class], $ctx->middleSources);
        self::assertSame([ContextFixture\D::class, ContextFixture\E::class], $ctx->tailSources);
    }

    #[Test]
    public function withCallsReturnNewInstance(): void
    {
        $original = Context::new();
        $extended = $original->front(ContextFixture\A::class);

        self::assertNotSame($original, $extended);
        self::assertTrue($original->isEmpty());
        self::assertFalse($extended->isEmpty());
    }

    #[Test]
    public function duplicatesDedupWithinASlot(): void
    {
        $ctx = Context::new()->front(
            ContextFixture\A::class,
            ContextFixture\A::class,
            ContextFixture\B::class,
        );

        self::assertCount(2, $ctx->frontSources);
    }

    #[Test]
    public function allConcatenatesFrontMiddleTailInOrder(): void
    {
        $ctx = Context::new()
            ->tail(ContextFixture\D::class)
            ->front(ContextFixture\A::class)
            ->middle(ContextFixture\C::class);

        self::assertSame(
            [ContextFixture\A::class, ContextFixture\C::class, ContextFixture\D::class],
            $ctx->all(),
        );
    }

    #[Test]
    public function canonicalFormPreservesSlotOrder(): void
    {
        $a = Context::new()->front(ContextFixture\A::class)->tail(ContextFixture\D::class);
        $b = Context::new()->tail(ContextFixture\D::class)->front(ContextFixture\A::class);

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function hashIsA64CharacterHexString(): void
    {
        $hash = Canonical::of(Context::new()->front(ContextFixture\A::class)->tail(ContextFixture\D::class));

        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }
}

namespace Phalanx\Panoply\Tests\Unit\ContextFixture;

final class A
{
}
final class B
{
}
final class C
{
}
final class D
{
}
final class E
{
}
