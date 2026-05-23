<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\HomeDir\Codex;

use Phalanx\Panoply\HomeDir\Codex\Settings;
use Phalanx\Panoply\HomeDir\SettingsError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Codex Settings no-op fallback path (TOML parser absent) and,
 * when a compatible TOML parser is present, the loaded-config path.
 */
final class SettingsTest extends TestCase
{
    // ── No-op fallback (always runs regardless of parser availability) ────────

    #[Test]
    public function hasReturnsFalseForAnyKeyWhenNoTomlParser(): void
    {
        $settings = self::noopSettings();

        if ($settings->tomlAvailable) {
            self::markTestSkipped('TOML parser present — no-op path not exercised.');
        }

        self::assertFalse($settings->has('model'));
        self::assertFalse($settings->has('anything'));
    }

    #[Test]
    public function getStringReturnsNullWhenNoTomlParser(): void
    {
        $settings = self::noopSettings();

        if ($settings->tomlAvailable) {
            self::markTestSkipped('TOML parser present — no-op path not exercised.');
        }

        self::assertNull($settings->getString('model'));
    }

    #[Test]
    public function getStringReturnsDefaultWhenNoTomlParser(): void
    {
        $settings = self::noopSettings();

        if ($settings->tomlAvailable) {
            self::markTestSkipped('TOML parser present — no-op path not exercised.');
        }

        self::assertSame('fallback', $settings->getString('model', 'fallback'));
    }

    #[Test]
    public function asStringThrowsSettingsErrorWhenNoTomlParser(): void
    {
        $settings = self::noopSettings();

        if ($settings->tomlAvailable) {
            self::markTestSkipped('TOML parser present — no-op path not exercised.');
        }

        $this->expectException(SettingsError::class);
        $settings->asString('model');
    }

    #[Test]
    public function getIntReturnsNullWhenNoTomlParser(): void
    {
        $settings = self::noopSettings();

        if ($settings->tomlAvailable) {
            self::markTestSkipped('TOML parser present — no-op path not exercised.');
        }

        self::assertNull($settings->getInt('timeout'));
    }

    #[Test]
    public function getIntReturnsDefaultWhenNoTomlParser(): void
    {
        $settings = self::noopSettings();

        if ($settings->tomlAvailable) {
            self::markTestSkipped('TOML parser present — no-op path not exercised.');
        }

        self::assertSame(42, $settings->getInt('timeout', 42));
    }

    #[Test]
    public function asIntThrowsSettingsErrorWhenNoTomlParser(): void
    {
        $settings = self::noopSettings();

        if ($settings->tomlAvailable) {
            self::markTestSkipped('TOML parser present — no-op path not exercised.');
        }

        $this->expectException(SettingsError::class);
        $settings->asInt('timeout');
    }

    #[Test]
    public function getBoolReturnsNullWhenNoTomlParser(): void
    {
        $settings = self::noopSettings();

        if ($settings->tomlAvailable) {
            self::markTestSkipped('TOML parser present — no-op path not exercised.');
        }

        self::assertNull($settings->getBool('verbose'));
    }

    #[Test]
    public function getBoolReturnsDefaultWhenNoTomlParser(): void
    {
        $settings = self::noopSettings();

        if ($settings->tomlAvailable) {
            self::markTestSkipped('TOML parser present — no-op path not exercised.');
        }

        self::assertTrue($settings->getBool('verbose', true));
    }

    #[Test]
    public function asBoolThrowsSettingsErrorWhenNoTomlParser(): void
    {
        $settings = self::noopSettings();

        if ($settings->tomlAvailable) {
            self::markTestSkipped('TOML parser present — no-op path not exercised.');
        }

        $this->expectException(SettingsError::class);
        $settings->asBool('verbose');
    }

    #[Test]
    public function getArrayReturnsNullWhenNoTomlParser(): void
    {
        $settings = self::noopSettings();

        if ($settings->tomlAvailable) {
            self::markTestSkipped('TOML parser present — no-op path not exercised.');
        }

        self::assertNull($settings->getArray('tools'));
    }

    #[Test]
    public function getArrayReturnsDefaultWhenNoTomlParser(): void
    {
        $settings = self::noopSettings();

        if ($settings->tomlAvailable) {
            self::markTestSkipped('TOML parser present — no-op path not exercised.');
        }

        self::assertSame(['default'], $settings->getArray('tools', ['default']));
    }

    #[Test]
    public function asArrayThrowsSettingsErrorWhenNoTomlParser(): void
    {
        $settings = self::noopSettings();

        if ($settings->tomlAvailable) {
            self::markTestSkipped('TOML parser present — no-op path not exercised.');
        }

        $this->expectException(SettingsError::class);
        $settings->asArray('tools');
    }

    #[Test]
    public function noConfigPathProducesNoopSettingsRegardlessOfParser(): void
    {
        $settings = new Settings(configTomlPath: null);

        self::assertFalse($settings->has('model'));
        self::assertNull($settings->getString('model'));
    }

    // ── Loaded-config path (runs only when a TOML parser is available) ────────

    #[Test]
    public function loadedConfigHasKeyForPresentKey(): void
    {
        $settings = self::loadedSettings();

        if (!$settings->tomlAvailable) {
            self::markTestSkipped('No TOML parser present — loaded-config path skipped.');
        }

        // fixture: model = "o4-mini"
        self::assertTrue($settings->has('model'));
    }

    #[Test]
    public function loadedConfigGetStringReturnsValue(): void
    {
        $settings = self::loadedSettings();

        if (!$settings->tomlAvailable) {
            self::markTestSkipped('No TOML parser present — loaded-config path skipped.');
        }

        self::assertSame('o4-mini', $settings->getString('model'));
    }

    #[Test]
    public function loadedConfigAsStringReturnsValue(): void
    {
        $settings = self::loadedSettings();

        if (!$settings->tomlAvailable) {
            self::markTestSkipped('No TOML parser present — loaded-config path skipped.');
        }

        self::assertSame('o4-mini', $settings->asString('model'));
    }

    #[Test]
    public function isAvailableReturnsTrueWhenTomlParserPresentAndFileLoaded(): void
    {
        $settings = self::loadedSettings();

        if (!$settings->tomlAvailable) {
            self::markTestSkipped('No TOML parser present — loaded-config path skipped.');
        }

        self::assertTrue($settings->isAvailable());
    }

    #[Test]
    public function tomlAvailableReflectsActualExtensionState(): void
    {
        // Use loadedSettings() (real file path) so the parser presence can
        // actually be reflected — noopSettings() uses a non-existent path and
        // returns tomlAvailable:false regardless of parser state.
        $settings = self::loadedSettings();
        $extensionPresent = class_exists(\Yosymfony\Toml\Toml::class);

        self::assertSame($extensionPresent, $settings->tomlAvailable);
    }

    #[Test]
    public function isAvailableReflectsTomlAvailabilityWhenNoParser(): void
    {
        $settings = self::noopSettings();

        // isAvailable() must mirror tomlAvailable exactly.
        self::assertSame($settings->tomlAvailable, $settings->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsFalseWhenNoConfigPath(): void
    {
        // null path always produces tomlAvailable=false regardless of parser.
        $settings = new Settings(configTomlPath: null);

        self::assertFalse($settings->isAvailable());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function noopSettings(): Settings
    {
        // Points at a non-existent file — always produces no-op mode.
        return new Settings(configTomlPath: '/does/not/exist/config.toml');
    }

    private static function loadedSettings(): Settings
    {
        $tomlPath = dirname(__DIR__, 3) . '/Fixtures/HomeDir/autoDetect/full/.codex/config.toml';

        return new Settings(configTomlPath: $tomlPath);
    }
}
