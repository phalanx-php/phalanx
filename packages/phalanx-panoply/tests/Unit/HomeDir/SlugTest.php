<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\HomeDir;

use Phalanx\Panoply\HomeDir\Slug;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins encoding/decoding behavior for the Claude Code path-slug utility.
 * Documents the lossy nature of the encoding (paths with literal `-` do
 * not round-trip cleanly) as intentional and matching Claude Code's own
 * behavior.
 */
final class SlugTest extends TestCase
{
    #[Test]
    public function encodeReplacesForwardSlashWithHyphen(): void
    {
        $slug = Slug::encode('/srv/phalanx/agora');

        self::assertSame('-srv-phalanx-agora', $slug);
    }

    #[Test]
    public function decodeReplacesHyphenWithForwardSlash(): void
    {
        $path = Slug::decode('-srv-phalanx-agora');

        self::assertSame('/srv/phalanx/agora', $path);
    }

    #[Test]
    public function encodeDecodeRoundTripForPathWithoutHyphens(): void
    {
        $original = '/srv/phalanx/sparta';
        $slug = Slug::encode($original);
        $decoded = Slug::decode($slug);

        self::assertSame($original, $decoded);
    }

    #[Test]
    public function encodeRootPathProducesLeadingHyphen(): void
    {
        self::assertSame('-', Slug::encode('/'));
    }

    #[Test]
    public function decodeLeadingHyphenProducesSlash(): void
    {
        self::assertSame('/', Slug::decode('-'));
    }

    #[Test]
    public function encodePreservesEmbeddedHyphens(): void
    {
        // Paths with literal `-` encode correctly even though decoding is lossy.
        $slug = Slug::encode('/home/user/my-project');

        self::assertSame('-home-user-my-project', $slug);
    }

    #[Test]
    public function decodeIsLossyForPathsContainingHyphens(): void
    {
        // This documents the intentional lossy behavior: a slug derived from
        // `/home/user/my-project` cannot be round-tripped because `-` is
        // indistinguishable from the `/` separator in the encoded form.
        $slug = '-home-user-my-project';
        $decoded = Slug::decode($slug);

        self::assertSame('/home/user/my/project', $decoded);
    }

    #[Test]
    public function encodeDeepPath(): void
    {
        $slug = Slug::encode('/srv/phalanx/Code/Me/Php/Phalanx/phalanx');

        self::assertSame('-srv-phalanx-Code-Me-Php-Phalanx-phalanx', $slug);
    }

    #[Test]
    public function decodeDeepSlug(): void
    {
        $path = Slug::decode('-srv-phalanx-Code-Me-Php-Phalanx-phalanx');

        self::assertSame('/srv/phalanx/Code/Me/Php/Phalanx/phalanx', $path);
    }

    #[Test]
    public function encodeEmptyStringReturnsEmptyString(): void
    {
        self::assertSame('', Slug::encode(''));
    }

    #[Test]
    public function decodeEmptyStringReturnsEmptyString(): void
    {
        self::assertSame('', Slug::decode(''));
    }
}
