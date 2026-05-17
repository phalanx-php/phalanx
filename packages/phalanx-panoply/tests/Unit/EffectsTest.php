<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Hash\Canonical;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Effects::class)]
final class EffectsTest extends TestCase
{
    public function test_none_permits_nothing(): void
    {
        $effects = Effects::none();

        self::assertTrue($effects->isEmpty());
        self::assertFalse($effects->permits(Kind::FileRead));
    }

    public function test_allow_grants_kinds(): void
    {
        $effects = Effects::allow(Kind::FileRead, Kind::CodeSearch);

        self::assertTrue($effects->permits(Kind::FileRead));
        self::assertTrue($effects->permits(Kind::CodeSearch));
        self::assertFalse($effects->permits(Kind::FileWrite));
    }

    public function test_require_approval_separate_from_allow(): void
    {
        $effects = Effects::allow(Kind::FileRead)
            ->requireApproval(Kind::FileWrite, Kind::ShellExec);

        self::assertFalse($effects->needsApproval(Kind::FileRead));
        self::assertTrue($effects->needsApproval(Kind::FileWrite));
        self::assertTrue($effects->needsApproval(Kind::ShellExec));
    }

    public function test_duplicates_dedup(): void
    {
        $effects = Effects::allow(Kind::FileRead, Kind::FileRead, Kind::CodeSearch);

        self::assertCount(2, $effects->allowed);
    }

    public function test_canonical_form_sorts_kinds(): void
    {
        $a = Effects::allow(Kind::WebFetch, Kind::FileRead, Kind::CodeSearch);
        $b = Effects::allow(Kind::CodeSearch, Kind::FileRead, Kind::WebFetch);

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }
}
