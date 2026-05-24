<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Conversation;

use Phalanx\Panoply\Conversation\Options;
use Phalanx\Panoply\Conversation\StrictMode;
use Phalanx\Panoply\Hash\Canonical;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    #[Test]
    public function defaultFactoryProducesLoudMode(): void
    {
        $opts = Options::default();

        self::assertSame(StrictMode::Loud, $opts->strictMode);
    }

    #[Test]
    public function lenientFactoryProducesLenientMode(): void
    {
        $opts = Options::lenient();

        self::assertSame(StrictMode::Lenient, $opts->strictMode);
    }

    #[Test]
    public function silentFactoryProducesSilentMode(): void
    {
        $opts = Options::silent();

        self::assertSame(StrictMode::Silent, $opts->strictMode);
    }

    #[Test]
    public function canonicalFormContainsStrictModeValue(): void
    {
        $canonical = Options::default()->toCanonical();

        self::assertArrayHasKey('strict_mode', $canonical);
        self::assertSame('loud', $canonical['strict_mode']);
    }

    #[Test]
    public function differentModesProduceDifferentCanonicalForms(): void
    {
        self::assertNotSame(
            Options::default()->toCanonical(),
            Options::lenient()->toCanonical(),
        );
    }

    #[Test]
    public function hashIsA64CharHexString(): void
    {
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Canonical::of(Options::default()));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Canonical::of(Options::lenient()));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Canonical::of(Options::silent()));
    }

    #[Test]
    public function sameFactoryCallsHashIdentically(): void
    {
        self::assertSame(Canonical::of(Options::default()), Canonical::of(Options::default()));
    }

    #[Test]
    public function differentModesHashDifferently(): void
    {
        self::assertNotSame(Canonical::of(Options::default()), Canonical::of(Options::lenient()));
        self::assertNotSame(Canonical::of(Options::lenient()), Canonical::of(Options::silent()));
    }
}
