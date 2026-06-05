<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Drawing;

use Phalanx\Tui\Tui\Drawing\ScreenMode;
use Phalanx\Tui\Tui\Drawing\StageConfig;
use Phalanx\Tui\Tui\Styles\ColorMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StageConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new StageConfig();

        self::assertSame(ScreenMode::Alternate, $config->screenMode);
        self::assertFalse($config->mouseTracking);
        self::assertTrue($config->bracketedPaste);
        self::assertFalse($config->syncOutput);
        self::assertFalse($config->handleInput);
        self::assertTrue($config->defaultExitHandler);
        self::assertSame(ColorMode::Ansi24, $config->colorMode);
        self::assertSame(10_000, $config->activeIntervalUs);
        self::assertSame(250_000, $config->idleIntervalUs);
        self::assertNull($config->stream);
        self::assertNull($config->captureFile);
        self::assertFalse($config->fullSgr);
        self::assertTrue($config->flushMemoryCaches);
    }

    #[Test]
    public function constructionWithOverrides(): void
    {
        $config = new StageConfig(
            screenMode: ScreenMode::Inline,
            mouseTracking: true,
            handleInput: true,
            colorMode: ColorMode::Ansi8,
            activeIntervalUs: 16_667,
        );

        self::assertSame(ScreenMode::Inline, $config->screenMode);
        self::assertTrue($config->mouseTracking);
        self::assertTrue($config->handleInput);
        self::assertSame(ColorMode::Ansi8, $config->colorMode);
        self::assertSame(16_667, $config->activeIntervalUs);
    }

    #[Test]
    public function screenModeDetect(): void
    {
        $config = new StageConfig(screenMode: ScreenMode::Detect);
        self::assertSame(ScreenMode::Detect, $config->screenMode);
    }
}
