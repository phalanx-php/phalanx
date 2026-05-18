<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\HomeDir\ClaudeCode;

use Phalanx\Panoply\HomeDir\ClaudeCode\Settings;
use Phalanx\Panoply\HomeDir\SettingsError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the ClaudeCode Settings sidecar+in-dir merge semantics.
 * The fixture files at tests/Fixtures/HomeDir/ClaudeCode/ contain overlapping
 * keys so we can verify in-dir wins.
 *
 * Fixture content:
 *   claude.json (sidecar): theme=dark, autoUpdate=true, nested.level1=sidecar-value, nested.onlyInSidecar=sparta
 *   settings.json (in-dir): theme=light, maxOutputTokens=8192, enableVision=true,
 *                             nested.level1=indir-wins, nested.onlyInDir=olympus
 */
final class SettingsTest extends TestCase
{
    #[Test]
    public function inDirWinsOnOverlappingStringKey(): void
    {
        // theme: sidecar=dark, in-dir=light → light wins.
        $settings = $this->buildSettings();

        self::assertSame('light', $settings->asString('theme'));
    }

    #[Test]
    public function sidecarOnlyKeyIsPreserved(): void
    {
        $settings = $this->buildSettings();

        self::assertTrue($settings->has('autoUpdate'));
        self::assertTrue($settings->asBool('autoUpdate'));
    }

    #[Test]
    public function inDirOnlyKeyIsPresent(): void
    {
        $settings = $this->buildSettings();

        self::assertTrue($settings->has('maxOutputTokens'));
        self::assertSame(8192, $settings->asInt('maxOutputTokens'));
    }

    #[Test]
    public function nestedObjectMergedDeepWithInDirWinning(): void
    {
        $settings = $this->buildSettings();
        $nested   = $settings->asArray('nested');

        // level1: sidecar=sidecar-value, in-dir=indir-wins → indir-wins
        self::assertSame('indir-wins', $nested['level1']);
    }

    #[Test]
    public function nestedKeyOnlyInSidecarIsPreserved(): void
    {
        $settings = $this->buildSettings();
        $nested   = $settings->asArray('nested');

        self::assertSame('sparta', $nested['onlyInSidecar']);
    }

    #[Test]
    public function nestedKeyOnlyInDirIsPresent(): void
    {
        $settings = $this->buildSettings();
        $nested   = $settings->asArray('nested');

        self::assertSame('olympus', $nested['onlyInDir']);
    }

    #[Test]
    public function missingKeyReturnsFalseFromHas(): void
    {
        $settings = $this->buildSettings();

        self::assertFalse($settings->has('nonExistentKey'));
    }

    #[Test]
    public function asStringThrowsOnMissingKey(): void
    {
        $settings = $this->buildSettings();

        $this->expectException(SettingsError::class);

        $settings->asString('missingKey');
    }

    #[Test]
    public function getStringReturnsDefaultForMissingKey(): void
    {
        $settings = $this->buildSettings();

        self::assertSame('defaultVal', $settings->getString('missingKey', 'defaultVal'));
    }

    #[Test]
    public function getStringReturnsNullForMissingKeyWithoutDefault(): void
    {
        $settings = $this->buildSettings();

        self::assertNull($settings->getString('missingKey'));
    }

    #[Test]
    public function asBoolThrowsOnTypeMismatch(): void
    {
        // theme is a string; asBoool should throw.
        $settings = $this->buildSettings();

        $this->expectException(SettingsError::class);
        $this->expectExceptionMessage('expected bool');

        $settings->asBool('theme');
    }

    #[Test]
    public function asIntThrowsOnTypeMismatch(): void
    {
        // theme is a string; asInt should throw.
        $settings = $this->buildSettings();

        $this->expectException(SettingsError::class);
        $this->expectExceptionMessage('expected int');

        $settings->asInt('theme');
    }

    #[Test]
    public function enableVisionIsBool(): void
    {
        $settings = $this->buildSettings();

        self::assertTrue($settings->asBool('enableVision'));
        self::assertTrue($settings->getBool('enableVision'));
    }

    #[Test]
    public function noFilesProducesEmptySettings(): void
    {
        $settings = new Settings(sidecarPath: null, inDirPath: null);

        self::assertFalse($settings->has('anything'));
        self::assertNull($settings->getString('anything'));
    }

    private static function fixtureRoot(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/HomeDir/ClaudeCode';
    }

    private function buildSettings(): Settings
    {
        $root = self::fixtureRoot();

        return new Settings(
            sidecarPath: $root . '/claude.json',
            inDirPath: $root . '/settings.json',
        );
    }
}
