<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\HomeDir\Codex;

use Phalanx\Panoply\HomeDir\Codex\Settings;
use Phalanx\Panoply\HomeDir\SettingsError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    #[Test]
    public function noConfigPathProducesUnavailableFallbacks(): void
    {
        $settings = new Settings(configTomlPath: null);

        self::assertFalse($settings->isAvailable());
        self::assertFalse($settings->has('model'));
        self::assertNull($settings->getString('model'));
        self::assertSame('fallback', $settings->getString('model', 'fallback'));
    }

    #[Test]
    public function missingConfigPathProducesUnavailableFallbacks(): void
    {
        $settings = new Settings(
            configTomlPath: sys_get_temp_dir() . '/' . uniqid('codex-settings-missing-', true) . '.toml',
        );

        self::assertFalse($settings->isAvailable());
        self::assertFalse($settings->has('model'));
        self::assertSame(['default'], $settings->getArray('tools', ['default']));
    }

    #[Test]
    public function requiredReadThrowsWhenSettingsAreUnavailable(): void
    {
        $settings = new Settings(configTomlPath: null);

        $this->expectException(SettingsError::class);

        $settings->asString('model');
    }

    #[Test]
    public function loadedConfigReadsCodexModel(): void
    {
        $settings = self::loadedSettings();

        if (!$settings->tomlAvailable) {
            self::markTestSkipped('No TOML parser present; loaded-config path skipped.');
        }

        self::assertTrue($settings->isAvailable());
        self::assertTrue($settings->has('model'));
        self::assertSame('o4-mini', $settings->getString('model'));
        self::assertSame('o4-mini', $settings->asString('model'));
    }

    #[Test]
    public function loadedConfigRequiredReadThrowsForMissingKey(): void
    {
        $settings = self::loadedSettings();

        if (!$settings->tomlAvailable) {
            self::markTestSkipped('No TOML parser present; loaded-config path skipped.');
        }

        $this->expectException(SettingsError::class);

        $settings->asString('missing');
    }

    private static function loadedSettings(): Settings
    {
        $tomlPath = dirname(__DIR__, 3) . '/Fixtures/HomeDir/autoDetect/full/.codex/config.toml';

        return new Settings(configTomlPath: $tomlPath);
    }
}
