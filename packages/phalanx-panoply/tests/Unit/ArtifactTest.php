<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Artifact;
use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Hash\Canonical;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArtifactTest extends TestCase
{
    #[Test]
    public function draftCreatesEmptyArtifact(): void
    {
        $artifact = self::fixture();

        self::assertSame('art_sparta_01', $artifact->id);
        self::assertSame(ArtifactKind::Thesis, $artifact->kind);
        self::assertSame('leonidas', $artifact->agentId);
        self::assertSame('Spartan Defense Brief', $artifact->title);
        self::assertSame('', $artifact->content);
        self::assertSame('', $artifact->contentHash);
        self::assertNull($artifact->updatedAt);
        self::assertNull($artifact->finalizedAt);
        self::assertFalse($artifact->isFinalized());
    }

    #[Test]
    public function withContentSetsContentAndClearsFinalization(): void
    {
        $draft    = self::fixture();
        $updated  = $draft->withContent('Thermopylae defense strategy outlines...');

        self::assertNotSame($draft, $updated);
        self::assertSame('Thermopylae defense strategy outlines...', $updated->content);
        self::assertSame('', $updated->contentHash);
        self::assertNotNull($updated->updatedAt);
        self::assertNull($updated->finalizedAt);
        self::assertFalse($updated->isFinalized());
    }

    #[Test]
    public function withContentPreservesIdentityFields(): void
    {
        $draft   = self::fixture();
        $updated = $draft->withContent('new content');

        self::assertSame($draft->id, $updated->id);
        self::assertSame($draft->kind, $updated->kind);
        self::assertSame($draft->agentId, $updated->agentId);
        self::assertSame($draft->title, $updated->title);
        self::assertSame($draft->createdAt, $updated->createdAt);
    }

    #[Test]
    public function finalizeSetsFinalizedAtAndContentHash(): void
    {
        $content  = 'Spartan defense doctrine';
        $hash     = hash('sha256', $content);
        $artifact = self::fixture()->withContent($content)->finalize($content, $hash);

        self::assertSame('Spartan defense doctrine', $artifact->content);
        self::assertSame($hash, $artifact->contentHash);
        self::assertNotNull($artifact->finalizedAt);
        self::assertTrue($artifact->isFinalized());
    }

    #[Test]
    public function finalizeReturnsNewInstance(): void
    {
        $updated   = self::fixture()->withContent('content');
        $finalized = $updated->finalize('content', 'abc123');

        self::assertNotSame($updated, $finalized);
    }

    #[Test]
    public function draftToWithContentToFinalizeLifecycle(): void
    {
        $content  = 'Hoplite formations for the agora defense';
        $hash     = hash('sha256', $content);
        $artifact = self::fixture()
            ->withContent($content)
            ->finalize($content, $hash);

        self::assertTrue($artifact->isFinalized());
        self::assertSame($content, $artifact->content);
        self::assertSame($hash, $artifact->contentHash);
    }

    #[Test]
    public function toCanonicalHasExpectedKeys(): void
    {
        $canonical = self::fixture()->toCanonical();

        self::assertArrayHasKey('id', $canonical);
        self::assertArrayHasKey('kind', $canonical);
        self::assertArrayHasKey('title', $canonical);
        self::assertArrayHasKey('content', $canonical);
        self::assertArrayHasKey('content_hash', $canonical);
        self::assertArrayHasKey('agent_id', $canonical);
        self::assertArrayHasKey('created_at', $canonical);
        self::assertArrayHasKey('updated_at', $canonical);
        self::assertArrayHasKey('finalized_at', $canonical);
        self::assertSame('thesis', $canonical['kind']);
    }

    #[Test]
    public function toCanonicalEmitsUtcTimestamps(): void
    {
        $createdAt = new \DateTimeImmutable('2026-05-17T12:00:00+05:00');
        $artifact  = Artifact::draft('art_x', ArtifactKind::Thesis, 'apollo', createdAt: $createdAt);
        $canonical = $artifact->toCanonical();

        self::assertStringContainsString('Z', $canonical['created_at']);
        // 12:00+05:00 = 07:00Z
        self::assertStringStartsWith('2026-05-17T07:00:00', $canonical['created_at']);
    }

    #[Test]
    public function hashDeterminism(): void
    {
        $ts = new \DateTimeImmutable('2026-05-17T00:00:00Z');
        $a  = Artifact::draft('art_determ', ArtifactKind::Thesis, 'leonidas', createdAt: $ts);
        $b  = Artifact::draft('art_determ', ArtifactKind::Thesis, 'leonidas', createdAt: $ts);

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function differentContentProducesDifferentHash(): void
    {
        $ts = new \DateTimeImmutable('2026-05-17T00:00:00Z');
        $a  = Artifact::draft('art_x', ArtifactKind::Thesis, 'odysseus', createdAt: $ts)->withContent('alpha');
        $b  = Artifact::draft('art_x', ArtifactKind::Thesis, 'odysseus', createdAt: $ts)->withContent('beta');

        self::assertNotSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function customKindIsCarried(): void
    {
        // Custom kind allows host- or vendor-defined output types; the kind
        // value in the canonical form is always the string 'custom'.
        $artifact  = Artifact::draft('art_custom_01', ArtifactKind::Custom, 'leonidas');
        $canonical = $artifact->toCanonical();

        self::assertSame('custom', $canonical['kind']);
    }

    #[Test]
    public function hashIsA64CharacterHexString(): void
    {
        $hash = Canonical::of(self::fixture());

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        self::assertSame(64, strlen($hash));
    }

    #[Test]
    public function timestampInDifferentTimezonesHashesIdentically(): void
    {
        $instant = new \DateTimeImmutable('2026-05-17T12:00:00+05:00');
        $utc     = new \DateTimeImmutable('2026-05-17T07:00:00Z');

        $a = Artifact::draft('art_tz', ArtifactKind::Thesis, 'leonidas', createdAt: $instant);
        $b = Artifact::draft('art_tz', ArtifactKind::Thesis, 'leonidas', createdAt: $utc);

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function finalizeTwicePreservesLatestContent(): void
    {
        $draft  = Artifact::draft('art_x', ArtifactKind::Thesis, 'leonidas');
        $first  = $draft->finalize('first', hash('sha256', 'first'));
        $second = $first->finalize('second', hash('sha256', 'second'));

        self::assertSame('second', $second->content);
        self::assertSame(hash('sha256', 'second'), $second->contentHash);
        // First is immutable; finalize returned a new instance.
        self::assertSame('first', $first->content);
    }

    #[Test]
    public function finalizeWithEmptyContentIsAllowed(): void
    {
        $draft     = Artifact::draft('art_x', ArtifactKind::Thesis, 'leonidas');
        $finalized = $draft->finalize('', hash('sha256', ''));

        self::assertTrue($finalized->isFinalized());
        self::assertSame('', $finalized->content);
    }

    private static function fixture(): Artifact
    {
        return Artifact::draft(
            id: 'art_sparta_01',
            kind: ArtifactKind::Thesis,
            agentId: 'leonidas',
            title: 'Spartan Defense Brief',
            createdAt: new \DateTimeImmutable('2026-05-17T00:00:00Z'),
        );
    }
}
