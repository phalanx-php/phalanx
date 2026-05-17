<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Hash;

use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Hash\Canonical;
use Phalanx\Panoply\Hash\UncanonicalizableValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Canonical::class)]
final class CanonicalTest extends TestCase
{
    public function test_scalar_hashes_are_stable(): void
    {
        $first = Canonical::of('hello');
        $second = Canonical::of('hello');

        self::assertSame($first, $second);
        self::assertSame(64, strlen($first), 'sha256 hex is 64 chars');
    }

    public function test_associative_array_key_order_is_irrelevant(): void
    {
        $hashA = Canonical::of(['b' => 2, 'a' => 1, 'c' => 3]);
        $hashB = Canonical::of(['c' => 3, 'a' => 1, 'b' => 2]);
        $hashC = Canonical::of(['a' => 1, 'b' => 2, 'c' => 3]);

        self::assertSame($hashA, $hashB);
        self::assertSame($hashB, $hashC);
    }

    public function test_list_order_is_preserved(): void
    {
        $hashAB = Canonical::of(['a', 'b']);
        $hashBA = Canonical::of(['b', 'a']);

        self::assertNotSame($hashAB, $hashBA);
    }

    public function test_backed_enum_normalizes_to_value(): void
    {
        $enumHash = Canonical::of(Capability::Reasoning);
        $stringHash = Canonical::of('reasoning');

        self::assertSame($enumHash, $stringHash);
    }

    public function test_unit_enum_normalizes_to_name(): void
    {
        $enumHash = Canonical::of(CanonicalFixture\Mood::Happy);
        $stringHash = Canonical::of('Happy');

        self::assertSame($enumHash, $stringHash);
    }

    public function test_canonicalizable_object_uses_its_form(): void
    {
        $caps = Capabilities::of(Capability::Reasoning, Capability::ToolUse);

        $hashFromObject = Canonical::of($caps);
        $hashFromArray  = Canonical::of([
            'cases'  => ['reasoning', 'tool-use'],
            'custom' => [],
        ]);

        self::assertSame($hashFromObject, $hashFromArray);
    }

    public function test_capabilities_hash_is_independent_of_input_order(): void
    {
        $a = Capabilities::of(Capability::Reasoning, Capability::ToolUse, Capability::Vision);
        $b = Capabilities::of(Capability::Vision, Capability::Reasoning, Capability::ToolUse);

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    public function test_arbitrary_object_is_rejected(): void
    {
        $this->expectException(UncanonicalizableValue::class);
        Canonical::of(new \stdClass());
    }

    public function test_closure_is_rejected(): void
    {
        $this->expectException(UncanonicalizableValue::class);
        Canonical::of(static fn (): int => 1);
    }

    public function test_nan_is_rejected(): void
    {
        $this->expectException(UncanonicalizableValue::class);
        Canonical::of(NAN);
    }

    public function test_positive_infinity_is_rejected(): void
    {
        $this->expectException(UncanonicalizableValue::class);
        Canonical::of(INF);
    }

    public function test_negative_infinity_is_rejected(): void
    {
        $this->expectException(UncanonicalizableValue::class);
        Canonical::of(-INF);
    }

    public function test_integer_and_integer_float_hash_distinctly(): void
    {
        // Floats keep their fractional form (`42.0` not `42`) so int 42 and
        // float 42.0 are never conflated. This is required for round-trip
        // determinism against external JCS verifiers.
        self::assertNotSame(Canonical::of(42), Canonical::of(42.0));
    }
}

namespace Phalanx\Panoply\Tests\Unit\Hash\CanonicalFixture;

enum Mood
{
    case Happy;
    case Sad;
}
