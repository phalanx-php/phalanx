<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hash\Canonical;
use Phalanx\Panoply\Hazard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GrantTest extends TestCase
{
    #[Test]
    public function ofConstructsGrant(): void
    {
        $grant = self::fixture();

        self::assertSame('grant_leonidas_01', $grant->id);
        self::assertSame('leonidas', $grant->subject);
        self::assertSame('workspace:sparta-project', $grant->scope);
        self::assertSame(Hazard::Medium, $grant->hazardCeiling);
        self::assertNull($grant->expiresAt);
        self::assertSame([], $grant->conditions);
    }

    #[Test]
    public function duplicateEffectKindsDedupOnConstruction(): void
    {
        $grant = Grant::of(
            id: 'grant_dup_01',
            subject: 'odysseus',
            allowedEffects: [EffectKind::FileRead, EffectKind::FileRead, EffectKind::WebFetch],
            scope: 'workspace:ithaca-project',
            hazardCeiling: Hazard::Low,
        );

        self::assertCount(2, $grant->allowedEffects);
    }

    #[Test]
    public function permitsReturnsTrueForAllowedKind(): void
    {
        $grant = self::fixture();

        self::assertTrue($grant->permits(EffectKind::FileRead));
        self::assertTrue($grant->permits(EffectKind::CodeSearch));
    }

    #[Test]
    public function permitsReturnsFalseForDisallowedKind(): void
    {
        $grant = self::fixture();

        self::assertFalse($grant->permits(EffectKind::ShellExec));
        self::assertFalse($grant->permits(EffectKind::FileWrite));
    }

    #[Test]
    public function withExpiryReturnsNewInstance(): void
    {
        $grant   = self::fixture();
        $expiry  = new \DateTimeImmutable('2026-12-01T00:00:00Z');
        $expired = $grant->withExpiry($expiry);

        self::assertNotSame($grant, $expired);
        self::assertNull($grant->expiresAt);
        self::assertSame($expiry, $expired->expiresAt);
    }

    #[Test]
    public function isExpiredReturnsTrueWhenPastExpiry(): void
    {
        $expiry = new \DateTimeImmutable('2026-05-01T00:00:00Z');
        $grant  = self::fixture()->withExpiry($expiry);

        self::assertTrue($grant->isExpired(new \DateTimeImmutable('2026-05-17T00:00:00Z')));
    }

    #[Test]
    public function isExpiredReturnsFalseWhenBeforeExpiry(): void
    {
        $expiry = new \DateTimeImmutable('2026-12-31T00:00:00Z');
        $grant  = self::fixture()->withExpiry($expiry);

        self::assertFalse($grant->isExpired(new \DateTimeImmutable('2026-05-17T00:00:00Z')));
    }

    #[Test]
    public function isExpiredReturnsFalseWhenNoExpiry(): void
    {
        self::assertFalse(self::fixture()->isExpired(new \DateTimeImmutable('2099-01-01T00:00:00Z')));
    }

    #[Test]
    public function withConditionReturnsNewInstance(): void
    {
        $grant    = self::fixture();
        $enriched = $grant->withCondition('region', 'sparta');

        self::assertNotSame($grant, $enriched);
        self::assertSame([], $grant->conditions);
        self::assertSame(['region' => 'sparta'], $enriched->conditions);
    }

    #[Test]
    public function toCanonicalHasExpectedKeysAndSortsAllowedEffects(): void
    {
        $grant     = self::fixture();
        $canonical = $grant->toCanonical();

        self::assertArrayHasKey('id', $canonical);
        self::assertArrayHasKey('subject', $canonical);
        self::assertArrayHasKey('allowed_effects', $canonical);
        self::assertArrayHasKey('scope', $canonical);
        self::assertArrayHasKey('hazard_ceiling', $canonical);
        self::assertArrayHasKey('expires_at', $canonical);
        self::assertArrayHasKey('conditions', $canonical);

        // allowed_effects must be sorted
        $effects = $canonical['allowed_effects'];
        $sorted  = $effects;
        sort($sorted);
        self::assertSame($sorted, $effects);
    }

    #[Test]
    public function hashDeterminism(): void
    {
        $a = self::fixture();
        $b = self::fixture();

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function differentSubjectProducesDifferentHash(): void
    {
        $a = Grant::of('g1', 'leonidas', [EffectKind::FileRead], 'workspace:sparta-project', Hazard::Low);
        $b = Grant::of('g1', 'apollo', [EffectKind::FileRead], 'workspace:sparta-project', Hazard::Low);

        self::assertNotSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function hashIsTimezoneIndependent(): void
    {
        // Same instant expressed in UTC and +05:00 must produce identical hashes.
        $utc   = new \DateTimeImmutable('2026-12-01T00:00:00Z');
        $plus5 = new \DateTimeImmutable('2026-12-01T05:00:00+05:00');

        $a = self::fixture()->withExpiry($utc);
        $b = self::fixture()->withExpiry($plus5);

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function isExpiredBoundaryAtExactExpiryInstant(): void
    {
        // isExpired uses >=, so a grant expires at the exact expiresAt instant.
        $expiry = new \DateTimeImmutable('2026-06-01T12:00:00Z');
        $grant  = self::fixture()->withExpiry($expiry);

        self::assertTrue($grant->isExpired($expiry));
    }

    #[Test]
    public function hashIsA64CharacterHexString(): void
    {
        $hash = Canonical::of(self::fixture());

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        self::assertSame(64, strlen($hash));
    }

    private static function fixture(): Grant
    {
        return Grant::of(
            id: 'grant_leonidas_01',
            subject: 'leonidas',
            allowedEffects: [EffectKind::FileRead, EffectKind::CodeSearch],
            scope: 'workspace:sparta-project',
            hazardCeiling: Hazard::Medium,
        );
    }
}
