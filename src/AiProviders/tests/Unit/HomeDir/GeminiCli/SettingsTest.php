<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\HomeDir\GeminiCli;

use Phalanx\AiProviders\HomeDir\GeminiCli\Settings;
use Phalanx\AiProviders\HomeDir\SettingsError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins GeminiCli Settings single-file behavior.
 * Fixture: tests/Fixtures/HomeDir/GeminiCli/settings.json
 *   model=gemini-2.5-pro, temperature=1, checkpointing=true, theme=dark, sandbox=false
 */
final class SettingsTest extends TestCase
{
    #[Test]
    public function hasReturnsTrueForExistingKey(): void
    {
        $settings = $this->buildSettings();

        self::assertTrue($settings->has('model'));
    }

    #[Test]
    public function asStringReturnsModelName(): void
    {
        $settings = $this->buildSettings();

        self::assertSame('gemini-2.5-pro', $settings->asString('model'));
    }

    #[Test]
    public function asIntReturnsTemperature(): void
    {
        $settings = $this->buildSettings();

        self::assertSame(1, $settings->asInt('temperature'));
    }

    #[Test]
    public function asBoolReturnsCheckpointing(): void
    {
        $settings = $this->buildSettings();

        self::assertTrue($settings->asBool('checkpointing'));
    }

    #[Test]
    public function asBoolReturnsFalseForSandbox(): void
    {
        $settings = $this->buildSettings();

        self::assertFalse($settings->asBool('sandbox'));
    }

    #[Test]
    public function hasReturnsFalseForMissingKey(): void
    {
        $settings = $this->buildSettings();

        self::assertFalse($settings->has('nonExistentKey'));
    }

    #[Test]
    public function asStringThrowsForMissingKey(): void
    {
        $settings = $this->buildSettings();

        $this->expectException(SettingsError::class);
        $settings->asString('missingKey');
    }

    #[Test]
    public function getStringReturnsDefaultForMissingKey(): void
    {
        $settings = $this->buildSettings();

        self::assertSame('fallback', $settings->getString('missingKey', 'fallback'));
    }

    #[Test]
    public function asBoolThrowsOnTypeMismatch(): void
    {
        $settings = $this->buildSettings();

        $this->expectException(SettingsError::class);
        $this->expectExceptionMessage('expected bool');

        $settings->asBool('model'); // model is a string, not bool
    }

    #[Test]
    public function noFileProducesEmptySettings(): void
    {
        $settings = new Settings(null);

        self::assertFalse($settings->has('anything'));
        self::assertNull($settings->getString('anything'));
    }

    #[Test]
    public function getBoolReturnsNullForMissingKey(): void
    {
        $settings = $this->buildSettings();

        self::assertNull($settings->getBool('missingKey'));
    }

    #[Test]
    public function getIntReturnsDefaultForMissingKey(): void
    {
        $settings = $this->buildSettings();

        self::assertSame(42, $settings->getInt('missingKey', 42));
    }

    #[Test]
    public function isAvailableReturnsTrue(): void
    {
        self::assertTrue($this->buildSettings()->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsTrueEvenWithNoFile(): void
    {
        $settings = new Settings(null);

        self::assertTrue($settings->isAvailable());
    }

    private static function fixtureRoot(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/HomeDir/GeminiCli';
    }

    private function buildSettings(): Settings
    {
        return new Settings(self::fixtureRoot() . '/settings.json');
    }
}
