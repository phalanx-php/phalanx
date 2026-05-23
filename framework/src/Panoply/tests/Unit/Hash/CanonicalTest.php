<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Hash;

use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Hash\Canonical;
use Phalanx\Panoply\Hash\UncanonicalizableValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CanonicalTest extends TestCase
{
    #[Test]
    public function scalarHashesAreStable(): void
    {
        $first = Canonical::of('hello');
        $second = Canonical::of('hello');

        self::assertSame($first, $second);
        self::assertSame(64, strlen($first), 'sha256 hex is 64 chars');
    }

    #[Test]
    public function associativeArrayKeyOrderIsIrrelevant(): void
    {
        $hashA = Canonical::of(['b' => 2, 'a' => 1, 'c' => 3]);
        $hashB = Canonical::of(['c' => 3, 'a' => 1, 'b' => 2]);
        $hashC = Canonical::of(['a' => 1, 'b' => 2, 'c' => 3]);

        self::assertSame($hashA, $hashB);
        self::assertSame($hashB, $hashC);
    }

    #[Test]
    public function listOrderIsPreserved(): void
    {
        $hashAB = Canonical::of(['a', 'b']);
        $hashBA = Canonical::of(['b', 'a']);

        self::assertNotSame($hashAB, $hashBA);
    }

    #[Test]
    public function backedEnumNormalizesToValue(): void
    {
        $enumHash = Canonical::of(Capability::Reasoning);
        $stringHash = Canonical::of('reasoning');

        self::assertSame($enumHash, $stringHash);
    }

    #[Test]
    public function unitEnumNormalizesToName(): void
    {
        $enumHash = Canonical::of(CanonicalFixture\Mood::Happy);
        $stringHash = Canonical::of('Happy');

        self::assertSame($enumHash, $stringHash);
    }

    #[Test]
    public function canonicalizableObjectUsesItsForm(): void
    {
        $caps = Capabilities::of(Capability::Reasoning, Capability::ToolUse);

        $hashFromObject = Canonical::of($caps);
        $hashFromArray = Canonical::of([
            'cases' => ['reasoning', 'tool-use'],
            'custom' => [],
        ]);

        self::assertSame($hashFromObject, $hashFromArray);
    }

    #[Test]
    public function capabilitiesHashIsIndependentOfInputOrder(): void
    {
        $a = Capabilities::of(Capability::Reasoning, Capability::ToolUse, Capability::Vision);
        $b = Capabilities::of(Capability::Vision, Capability::Reasoning, Capability::ToolUse);

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function arbitraryObjectIsRejected(): void
    {
        $this->expectException(UncanonicalizableValue::class);
        Canonical::of(new \stdClass());
    }

    #[Test]
    public function closureIsRejected(): void
    {
        $this->expectException(UncanonicalizableValue::class);
        Canonical::of(static fn (): int => 1);
    }

    #[Test]
    public function resourceIsRejected(): void
    {
        $resource = fopen('php://memory', 'rb');
        try {
            $this->expectException(UncanonicalizableValue::class);
            Canonical::of($resource);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    #[Test]
    public function nanIsRejected(): void
    {
        $this->expectException(UncanonicalizableValue::class);
        Canonical::of(NAN);
    }

    #[Test]
    public function positiveInfinityIsRejected(): void
    {
        $this->expectException(UncanonicalizableValue::class);
        Canonical::of(INF);
    }

    #[Test]
    public function negativeInfinityIsRejected(): void
    {
        $this->expectException(UncanonicalizableValue::class);
        Canonical::of(-INF);
    }

    #[Test]
    public function integerAndIntegerFloatHashDistinctly(): void
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
