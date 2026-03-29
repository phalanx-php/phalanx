<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Tests\Unit\Style;

use Phalanx\Terminal\Style\Color;
use Phalanx\Terminal\Style\ColorMode;
use Phalanx\Terminal\Style\Modifier;
use Phalanx\Terminal\Style\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
    #[Test]
    public function new_style_is_empty(): void
    {
        $style = Style::new();

        self::assertTrue($style->isEmpty);
    }

    #[Test]
    public function fg_produces_non_empty_style(): void
    {
        $style = Style::new()->fg('red');

        self::assertFalse($style->isEmpty);
    }

    #[Test]
    public function bold_sets_modifier(): void
    {
        $style = Style::new()->bold();

        self::assertTrue($style->hasModifier(Modifier::Bold));
        self::assertFalse($style->hasModifier(Modifier::Dim));
    }

    #[Test]
    public function sgr_emits_ansi4_foreground(): void
    {
        $style = Style::new()->fg('red');

        $sgr = $style->sgr(ColorMode::Ansi4);

        self::assertSame("\033[31m", $sgr);
    }

    #[Test]
    public function sgr_emits_truecolor(): void
    {
        $style = Style::new()->fg('#ff0000');

        $sgr = $style->sgr(ColorMode::Ansi24);

        self::assertSame("\033[38;2;255;0;0m", $sgr);
    }

    #[Test]
    public function sgr_combines_modifiers_and_colors(): void
    {
        $style = Style::new()->bold()->fg('green');

        $sgr = $style->sgr(ColorMode::Ansi4);

        self::assertSame("\033[1;32m", $sgr);
    }

    #[Test]
    public function patch_merges_styles(): void
    {
        $base = Style::new()->fg('red')->bold();
        $overlay = Style::new()->bg('blue')->dim();

        $merged = $base->patch($overlay);

        self::assertTrue($merged->hasModifier(Modifier::Bold));
        self::assertTrue($merged->hasModifier(Modifier::Dim));
    }

    #[Test]
    public function patch_overrides_fg(): void
    {
        $base = Style::new()->fg('red');
        $overlay = Style::new()->fg('green');

        $merged = $base->patch($overlay);
        $sgr = $merged->sgr(ColorMode::Ansi4);

        self::assertSame("\033[32m", $sgr);
    }

    #[Test]
    public function equals_compares_correctly(): void
    {
        $a = Style::new()->fg('red')->bold();
        $b = Style::new()->fg('red')->bold();
        $c = Style::new()->fg('blue')->bold();

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    #[Test]
    public function immutable_chaining(): void
    {
        $original = Style::new();
        $modified = $original->bold()->fg('cyan');

        self::assertTrue($original->isEmpty);
        self::assertFalse($modified->isEmpty);
    }

    #[Test]
    public function hex_color_resolves(): void
    {
        $style = Style::new()->fg('#00ff00');

        $sgr = $style->sgr(ColorMode::Ansi24);

        self::assertSame("\033[38;2;0;255;0m", $sgr);
    }

    #[Test]
    public function empty_sgr_returns_empty_string(): void
    {
        self::assertSame('', Style::new()->sgr(ColorMode::Ansi24));
    }
}
