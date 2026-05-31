<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Harness\Support;

use Phalanx\Theatron\Harness\Message\MessageKind;
use Phalanx\Theatron\Harness\Support\CanonicalHash;
use Phalanx\Theatron\Harness\Support\HarnessId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SupportContractTest extends TestCase
{
    #[Test]
    public function generatedHarnessIdsKeepTheRequestedPrefix(): void
    {
        $id = HarnessId::new('env');

        self::assertStringStartsWith('env_', $id);
        self::assertMatchesRegularExpression('/^env_[0-9a-f]+$/', $id);
    }

    #[Test]
    public function harnessIdsRejectBlankPrefixes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prefix cannot be empty');

        HarnessId::new(' ');
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
