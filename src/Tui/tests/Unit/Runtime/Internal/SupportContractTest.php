<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Runtime\Internal;

use Phalanx\Tui\Runtime\Internal\CanonicalHash;
use Phalanx\Tui\Runtime\Internal\Id;
use Phalanx\Tui\Runtime\Messages\MessageKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SupportContractTest extends TestCase
{
    #[Test]
    public function generatedIdsKeepTheRequestedPrefix(): void
    {
        $id = Id::new('env');

        self::assertStringStartsWith('env_', $id);
        self::assertMatchesRegularExpression('/^env_[0-9a-f]+$/', $id);
    }

    #[Test]
    public function runtimeIdsRejectBlankPrefixes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prefix cannot be empty');

        Id::new(' ');
    }

    #[Test]
    public function canonicalHashesIgnoreAssociativeInsertionOrder(): void
    {
        $left = CanonicalHash::of([
            'b' => ['value' => 2],
            'a' => MessageKind::Prompt,
        ]);
        $right = CanonicalHash::of([
            'a' => MessageKind::Prompt,
            'b' => ['value' => 2],
        ]);

        self::assertSame($left, $right);
    }

    #[Test]
    public function canonicalHashesRejectUnsupportedValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot canonicalize value');

        CanonicalHash::of(['object' => new \stdClass()]);
    }

    #[Test]
    public function canonicalHashesRejectNonFiniteFloats(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('NaN and Infinity');

        CanonicalHash::of(['float' => NAN]);
    }
}
