<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit;

use Phalanx\AiProviders\Effect\Kind;
use Phalanx\AiProviders\Effects;
use Phalanx\AiProviders\Hash\Canonical;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EffectsTest extends TestCase
{
    #[Test]
    public function nonePermitsNothing(): void
    {
        $effects = Effects::none();

        self::assertTrue($effects->isEmpty());
        self::assertFalse($effects->permits(Kind::FileRead));
    }

    #[Test]
    public function allowGrantsKinds(): void
    {
        $effects = Effects::allow(Kind::FileRead, Kind::CodeSearch);

        self::assertTrue($effects->permits(Kind::FileRead));
        self::assertTrue($effects->permits(Kind::CodeSearch));
        self::assertFalse($effects->permits(Kind::FileWrite));
    }

    #[Test]
    public function requireApprovalSeparateFromAllow(): void
    {
        $effects = Effects::allow(Kind::FileRead)
            ->requireApproval(Kind::FileWrite, Kind::ShellExec);

        self::assertFalse($effects->needsApproval(Kind::FileRead));
        self::assertTrue($effects->needsApproval(Kind::FileWrite));
        self::assertTrue($effects->needsApproval(Kind::ShellExec));
    }

    #[Test]
    public function duplicatesDedup(): void
    {
        $effects = Effects::allow(Kind::FileRead, Kind::FileRead, Kind::CodeSearch);

        self::assertCount(2, $effects->allowed);
    }

    #[Test]
    public function canonicalFormSortsKinds(): void
    {
        $a = Effects::allow(Kind::WebFetch, Kind::FileRead, Kind::CodeSearch);
        $b = Effects::allow(Kind::CodeSearch, Kind::FileRead, Kind::WebFetch);

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function hashIsA64CharacterHexString(): void
    {
        $hash = Canonical::of(Effects::allow(Kind::FileRead, Kind::CodeSearch));

        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    #[Test]
    public function differentEffectsProduceDifferentHashes(): void
    {
        $a = Effects::allow(Kind::FileRead);
        $b = Effects::allow(Kind::FileWrite);

        self::assertNotSame(Canonical::of($a), Canonical::of($b));
    }
}
