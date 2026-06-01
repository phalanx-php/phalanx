<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Tui\Styles;

use Phalanx\Theatron\Tui\Styles\Color;
use Phalanx\Theatron\Tui\Styles\ColorMode;
use Phalanx\Theatron\Tui\Styles\Modifier;
use Phalanx\Theatron\Tui\Styles\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
    #[Test]
    public function emptyStyleProducesNoSgr(): void
    {
        self::assertSame('', Style::new()->sgr(ColorMode::Ansi24));
    }

    #[Test]
    public function boldAddsModifierBit(): void
    {
        $style = Style::new()->bold();

        self::assertTrue($style->hasModifier(Modifier::Bold));
        self::assertSame("\033[1m", $style->sgr(ColorMode::Ansi24));
    }

    #[Test]
    public function patchMergesForegroundAndModifiers(): void
    {
        $base = Style::new()->fg(Color::red());
        $overlay = Style::new()->bold();
        $merged = $base->patch($overlay);

        self::assertTrue($merged->hasModifier(Modifier::Bold));
        self::assertNotNull($merged->foreground);
        self::assertTrue($merged->foreground->equals(Color::red()));
    }

    #[Test]
    public function equalityComparesFgBgAndModifiers(): void
    {
        $a = Style::new()->fg(Color::blue())->bold();
        $b = Style::new()->fg(Color::blue())->bold();
        $c = Style::new()->fg(Color::red())->bold();

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
