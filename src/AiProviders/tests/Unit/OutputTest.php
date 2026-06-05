<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit;

use Phalanx\AiProviders\Artifact\Kind as ArtifactKind;
use Phalanx\AiProviders\Hash\Canonical;
use Phalanx\AiProviders\Output;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    #[Test]
    public function textMode(): void
    {
        $out = Output::text();

        self::assertSame(Output\Mode::Text, $out->mode);
        self::assertNull($out->artifactKind);
        self::assertNull($out->schema);
    }

    #[Test]
    public function artifactModeCarriesKind(): void
    {
        $out = Output::artifact(ArtifactKind::Thesis);

        self::assertSame(Output\Mode::Artifact, $out->mode);
        self::assertSame(ArtifactKind::Thesis, $out->artifactKind);
        self::assertNull($out->schema);
    }

    #[Test]
    public function structuredModeCarriesSchema(): void
    {
        $out = Output::structured(OutputFixture\Schema::class);

        self::assertSame(Output\Mode::Structured, $out->mode);
        self::assertSame(OutputFixture\Schema::class, $out->schema);
        self::assertNull($out->artifactKind);
    }

    #[Test]
    public function sameModeHashesIdentically(): void
    {
        $a = Output::artifact(ArtifactKind::Thesis);
        $b = Output::artifact(ArtifactKind::Thesis);

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function distinctModesHashDifferently(): void
    {
        $text = Output::text();
        $artifact = Output::artifact(ArtifactKind::Thesis);
        $structured = Output::structured(OutputFixture\Schema::class);

        self::assertNotSame(Canonical::of($text), Canonical::of($artifact));
        self::assertNotSame(Canonical::of($text), Canonical::of($structured));
        self::assertNotSame(Canonical::of($artifact), Canonical::of($structured));
    }

    #[Test]
    public function hashIsA64CharacterHexString(): void
    {
        $hash = Canonical::of(Output::artifact(ArtifactKind::Thesis));

        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }
}

namespace Phalanx\AiProviders\Tests\Unit\OutputFixture;

final class Schema
{
}
