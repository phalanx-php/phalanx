<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Context;
use Phalanx\Panoply\Hash\Canonical;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Context::class)]
final class ContextTest extends TestCase
{
    public function test_new_is_empty(): void
    {
        self::assertTrue(Context::new()->isEmpty());
    }

    public function test_front_middle_tail_accumulate(): void
    {
        $ctx = Context::new()
            ->front(ContextFixture\A::class, ContextFixture\B::class)
            ->middle(ContextFixture\C::class)
            ->tail(ContextFixture\D::class, ContextFixture\E::class);

        self::assertSame([ContextFixture\A::class, ContextFixture\B::class], $ctx->frontSources);
        self::assertSame([ContextFixture\C::class], $ctx->middleSources);
        self::assertSame([ContextFixture\D::class, ContextFixture\E::class], $ctx->tailSources);
    }

    public function test_with_calls_return_new_instance(): void
    {
        $original = Context::new();
        $extended = $original->front(ContextFixture\A::class);

        self::assertNotSame($original, $extended);
        self::assertTrue($original->isEmpty());
        self::assertFalse($extended->isEmpty());
    }

    public function test_duplicates_dedup_within_a_slot(): void
    {
        $ctx = Context::new()->front(
            ContextFixture\A::class,
            ContextFixture\A::class,
            ContextFixture\B::class,
        );

        self::assertCount(2, $ctx->frontSources);
    }

    public function test_all_concatenates_front_middle_tail_in_order(): void
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

    public function test_canonical_form_preserves_slot_order(): void
    {
        $a = Context::new()->front(ContextFixture\A::class)->tail(ContextFixture\D::class);
        $b = Context::new()->tail(ContextFixture\D::class)->front(ContextFixture\A::class);

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }
}

namespace Phalanx\Panoply\Tests\Unit\ContextFixture;

final class A {}
final class B {}
final class C {}
final class D {}
final class E {}
