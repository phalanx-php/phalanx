<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\HomeDir;

use Phalanx\AiProviders\HomeDir\AbstractMapSettings;
use Phalanx\AiProviders\HomeDir\SettingsError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises {@see AbstractMapSettings} typed accessors via an anonymous subclass.
 * Verifies both fail-loud `as*()` paths and nullable `get*()` paths against a
 * fixed map of known types. Also confirms the default `isAvailable()` returns true.
 */
final class AbstractMapSettingsTest extends TestCase
{
    // ── has() ──────────────────────────────────────────────────────────────────

    #[Test]
    public function hasReturnsTrueForPresentKey(): void
    {
        self::assertTrue(self::make()->has('city'));
    }

    #[Test]
    public function hasReturnsFalseForAbsentKey(): void
    {
        self::assertFalse(self::make()->has('missing'));
    }

    // ── isAvailable() default ─────────────────────────────────────────────────

    #[Test]
    public function isAvailableReturnsTrueByDefault(): void
    {
        self::assertTrue(self::make()->isAvailable());
    }

    // ── asString() ─────────────────────────────────────────────────────────────

    #[Test]
    public function asStringReturnsValue(): void
    {
        self::assertSame('sparta', self::make()->asString('city'));
    }

    #[Test]
    public function asStringThrowsOnMissingKey(): void
    {
        $this->expectException(SettingsError::class);
        self::make()->asString('missing');
    }

    #[Test]
    public function asStringThrowsOnTypeMismatch(): void
    {
        $this->expectException(SettingsError::class);
        $this->expectExceptionMessage('expected string');
        self::make()->asString('count');
    }

    // ── getString() ────────────────────────────────────────────────────────────

    #[Test]
    public function getStringReturnsValueForPresentKey(): void
    {
        self::assertSame('sparta', self::make()->getString('city'));
    }

    #[Test]
    public function getStringReturnsNullForMissingKey(): void
    {
        self::assertNull(self::make()->getString('missing'));
    }

    #[Test]
    public function getStringReturnsDefaultForMissingKey(): void
    {
        self::assertSame('marathon', self::make()->getString('missing', 'marathon'));
    }

    // ── asInt() ────────────────────────────────────────────────────────────────

    #[Test]
    public function asIntReturnsValue(): void
    {
        self::assertSame(300, self::make()->asInt('count'));
    }

    #[Test]
    public function asIntThrowsOnMissingKey(): void
    {
        $this->expectException(SettingsError::class);
        self::make()->asInt('missing');
    }

    #[Test]
    public function asIntThrowsOnTypeMismatch(): void
    {
        $this->expectException(SettingsError::class);
        $this->expectExceptionMessage('expected int');
        self::make()->asInt('city');
    }

    // ── getInt() ───────────────────────────────────────────────────────────────

    #[Test]
    public function getIntReturnsValueForPresentKey(): void
    {
        self::assertSame(300, self::make()->getInt('count'));
    }

    #[Test]
    public function getIntReturnsNullForMissingKey(): void
    {
        self::assertNull(self::make()->getInt('missing'));
    }

    #[Test]
    public function getIntReturnsDefaultForMissingKey(): void
    {
        self::assertSame(42, self::make()->getInt('missing', 42));
    }

    // ── asBool() ───────────────────────────────────────────────────────────────

    #[Test]
    public function asBoolReturnsTrueValue(): void
    {
        self::assertTrue(self::make()->asBool('active'));
    }

    #[Test]
    public function asBoolReturnsFalseValue(): void
    {
        self::assertFalse(self::make()->asBool('defeated'));
    }

    #[Test]
    public function asBoolThrowsOnMissingKey(): void
    {
        $this->expectException(SettingsError::class);
        self::make()->asBool('missing');
    }

    #[Test]
    public function asBoolThrowsOnTypeMismatch(): void
    {
        $this->expectException(SettingsError::class);
        $this->expectExceptionMessage('expected bool');
        self::make()->asBool('city');
    }

    // ── getBool() ──────────────────────────────────────────────────────────────

    #[Test]
    public function getBoolReturnsValueForPresentKey(): void
    {
        self::assertTrue(self::make()->getBool('active'));
    }

    #[Test]
    public function getBoolReturnsNullForMissingKey(): void
    {
        self::assertNull(self::make()->getBool('missing'));
    }

    #[Test]
    public function getBoolReturnsDefaultForMissingKey(): void
    {
        self::assertTrue(self::make()->getBool('missing', true));
    }

    // ── asArray() ──────────────────────────────────────────────────────────────

    #[Test]
    public function asArrayReturnsValue(): void
    {
        self::assertSame(['leonidas', 'achilles'], self::make()->asArray('heroes'));
    }

    #[Test]
    public function asArrayThrowsOnMissingKey(): void
    {
        $this->expectException(SettingsError::class);
        self::make()->asArray('missing');
    }

    #[Test]
    public function asArrayThrowsOnTypeMismatch(): void
    {
        $this->expectException(SettingsError::class);
        $this->expectExceptionMessage('expected array');
        self::make()->asArray('city');
    }

    // ── getArray() ─────────────────────────────────────────────────────────────

    #[Test]
    public function getArrayReturnsValueForPresentKey(): void
    {
        self::assertSame(['leonidas', 'achilles'], self::make()->getArray('heroes'));
    }

    #[Test]
    public function getArrayReturnsNullForMissingKey(): void
    {
        self::assertNull(self::make()->getArray('missing'));
    }

    #[Test]
    public function getArrayReturnsDefaultForMissingKey(): void
    {
        self::assertSame(['olympus'], self::make()->getArray('missing', ['olympus']));
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private static function make(): AbstractMapSettings
    {
        return new class ([
            'city' => 'sparta',
            'count' => 300,
            'active' => true,
            'defeated' => false,
            'heroes' => ['leonidas', 'achilles'],
        ]) extends AbstractMapSettings {
        };
    }
}
