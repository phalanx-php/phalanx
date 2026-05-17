<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Hash\Canonical;
use Phalanx\Panoply\Output;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Output::class)]
final class OutputTest extends TestCase
{
    public function test_text_mode(): void
    {
        $out = Output::text();

        self::assertSame(Output\Mode::Text, $out->mode);
        self::assertNull($out->artifactKind);
        self::assertNull($out->schema);
    }

    public function test_artifact_mode_carries_kind(): void
    {
        $out = Output::artifact(ArtifactKind::Thesis);

        self::assertSame(Output\Mode::Artifact, $out->mode);
        self::assertSame(ArtifactKind::Thesis, $out->artifactKind);
        self::assertNull($out->schema);
    }

    public function test_structured_mode_carries_schema(): void
    {
        $out = Output::structured(OutputFixture\Schema::class);

        self::assertSame(Output\Mode::Structured, $out->mode);
        self::assertSame(OutputFixture\Schema::class, $out->schema);
        self::assertNull($out->artifactKind);
    }

    public function test_same_mode_hashes_identically(): void
    {
        $a = Output::artifact(ArtifactKind::Thesis);
        $b = Output::artifact(ArtifactKind::Thesis);

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    public function test_distinct_modes_hash_differently(): void
    {
        $text      = Output::text();
        $artifact  = Output::artifact(ArtifactKind::Thesis);
        $structured = Output::structured(OutputFixture\Schema::class);

        self::assertNotSame(Canonical::of($text), Canonical::of($artifact));
        self::assertNotSame(Canonical::of($text), Canonical::of($structured));
        self::assertNotSame(Canonical::of($artifact), Canonical::of($structured));
    }
}

namespace Phalanx\Panoply\Tests\Unit\OutputFixture;

final class Schema {}
