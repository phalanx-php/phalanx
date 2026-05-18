<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Effect;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Hash\Canonical;
use Phalanx\Panoply\Hazard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EffectTest extends TestCase
{
    #[Test]
    public function ofConstructsEffect(): void
    {
        $effect = self::fixture();

        self::assertSame('eff_sparta_01', $effect->id);
        self::assertSame(EffectKind::FileRead, $effect->kind);
        self::assertSame('read hoplite roster from /var/agora', $effect->summary);
        self::assertFalse($effect->requiresApproval);
        self::assertNull($effect->hazard);
    }

    #[Test]
    public function ofCarriesArgumentsAndApprovalFlag(): void
    {
        $effect = Effect::of(
            id: 'eff_marathon_01',
            kind: EffectKind::ShellExec,
            summary: 'execute battle formation script',
            arguments: ['formation' => 'phalanx', 'ranks' => 8],
            requiresApproval: true,
        );

        self::assertSame(['formation' => 'phalanx', 'ranks' => 8], $effect->arguments);
        self::assertTrue($effect->requiresApproval);
    }

    #[Test]
    public function withHazardReturnsNewInstance(): void
    {
        $original = self::fixture();
        $scored = $original->withHazard(Hazard::Medium);

        self::assertNotSame($original, $scored);
        self::assertNull($original->hazard);
        self::assertSame(Hazard::Medium, $scored->hazard);
    }

    #[Test]
    public function withHazardPreservesOtherFields(): void
    {
        $original = self::fixture();
        $scored = $original->withHazard(Hazard::Low);

        self::assertSame($original->id, $scored->id);
        self::assertSame($original->kind, $scored->kind);
        self::assertSame($original->summary, $scored->summary);
    }

    #[Test]
    public function toCanonicalHasExpectedKeys(): void
    {
        $effect = self::fixture()->withHazard(Hazard::High);
        $canonical = $effect->toCanonical();

        self::assertArrayHasKey('id', $canonical);
        self::assertArrayHasKey('kind', $canonical);
        self::assertArrayHasKey('summary', $canonical);
        self::assertArrayHasKey('arguments', $canonical);
        self::assertArrayHasKey('requires_approval', $canonical);
        self::assertArrayHasKey('hazard', $canonical);
        self::assertSame('high', $canonical['hazard']);
        self::assertSame('file.read', $canonical['kind']);
    }

    #[Test]
    public function toCanonicalNullHazardEmitsNull(): void
    {
        $canonical = self::fixture()->toCanonical();

        self::assertNull($canonical['hazard']);
    }

    #[Test]
    public function hashDeterminism(): void
    {
        $a = self::fixture();
        $b = self::fixture();

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function differentSummaryProducesDifferentHash(): void
    {
        $a = Effect::of('eff_x', EffectKind::FileRead, 'read agora records');
        $b = Effect::of('eff_x', EffectKind::FileRead, 'read olympus records');

        self::assertNotSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function customKindIsCarried(): void
    {
        // Custom kind carries an opaque subtype via $arguments; the kind
        // value itself is the string 'custom' per Effect\Kind::Custom.
        $effect = Effect::of(
            id: 'eff_custom_01',
            kind: EffectKind::Custom,
            summary: 'vault.note.append',
            arguments: ['subtype' => 'vault.note.append'],
        );
        $canonical = $effect->toCanonical();

        self::assertSame('custom', $canonical['kind']);
    }

    #[Test]
    public function hashIsA64CharacterHexString(): void
    {
        $hash = Canonical::of(self::fixture());

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        self::assertSame(64, strlen($hash));
    }

    private static function fixture(): Effect
    {
        return Effect::of(
            id: 'eff_sparta_01',
            kind: EffectKind::FileRead,
            summary: 'read hoplite roster from /var/agora',
        );
    }
}
