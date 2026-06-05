<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Provider;

use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Hash\Canonical;
use Phalanx\AiProviders\Provider\Needs;
use Phalanx\AiProviders\Provider\Preference;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NeedsTest extends TestCase
{
    #[Test]
    public function newIsEmpty(): void
    {
        self::assertTrue(Needs::new()->isEmpty());
    }

    #[Test]
    public function preferPlacesPreferenceFirst(): void
    {
        $needs = Needs::new()->prefer(Preference::LocalFirst);

        self::assertSame(Preference::LocalFirst, $needs->preferences[0]);
    }

    #[Test]
    public function fallbackAppendsToEnd(): void
    {
        $needs = Needs::new()
            ->prefer(Preference::LocalFirst)
            ->fallback(Preference::Hosted);

        self::assertSame(Preference::LocalFirst, $needs->preferences[0]);
        self::assertSame(Preference::Hosted, $needs->preferences[1]);
    }

    #[Test]
    public function preferReordersExistingPreferenceToFront(): void
    {
        $needs = Needs::new()
            ->fallback(Preference::Hosted)
            ->prefer(Preference::LocalFirst)
            ->prefer(Preference::Hosted);

        self::assertSame(Preference::Hosted, $needs->preferences[0]);
        self::assertSame(Preference::LocalFirst, $needs->preferences[1]);
    }

    #[Test]
    public function requireAccumulatesCapabilities(): void
    {
        $needs = Needs::new()
            ->require(Capability::Reasoning)
            ->require(Capability::ToolUse);

        self::assertTrue($needs->required->has(Capability::Reasoning));
        self::assertTrue($needs->required->has(Capability::ToolUse));
    }

    #[Test]
    public function immutability(): void
    {
        $original = Needs::new();
        $extended = $original->prefer(Preference::LocalFirst);

        self::assertNotSame($original, $extended);
        self::assertTrue($original->isEmpty());
    }

    #[Test]
    public function hashIsStableAcrossReconstruction(): void
    {
        $a = Needs::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning);
        $b = Needs::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning);
        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function differentPreferencesProduceDifferentHashes(): void
    {
        $a = Needs::new()->prefer(Preference::LocalFirst);
        $b = Needs::new()->prefer(Preference::Hosted);
        self::assertNotSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function hashIsA64CharacterHexString(): void
    {
        $hash = Canonical::of(Needs::new()->prefer(Preference::LocalFirst));
        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }
}
