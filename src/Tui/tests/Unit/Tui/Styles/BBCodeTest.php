<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Styles;

use Phalanx\Tui\Tui\Styles\Color;
use Phalanx\Tui\Tui\Styles\Modifier;
use Phalanx\Tui\Tui\Styles\BBCode;
use Phalanx\Tui\Tui\Styles\Theme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BBCodeTest extends TestCase
{
    private Theme $theme;

    #[Test]
    public function plainTextFastPath(): void
    {
        $line = BBCode::parse('hello world', $this->theme);

        self::assertCount(1, $line->spans);
        self::assertSame('hello world', $line->spans[0]->content);
        self::assertTrue($line->spans[0]->style->isEmpty);
    }

    #[Test]
    public function emptyStringReturnsEmptyLine(): void
    {
        $line = BBCode::parse('', $this->theme);

        self::assertCount(1, $line->spans);
        self::assertSame('', $line->spans[0]->content);
    }

    #[Test]
    public function singleModifierBold(): void
    {
        $line = BBCode::parse('[bold]text[/]', $this->theme);

        self::assertCount(1, $line->spans);
        self::assertSame('text', $line->spans[0]->content);
        self::assertTrue($line->spans[0]->style->hasModifier(Modifier::Bold));
    }

    #[Test]
    public function nestedModifiers(): void
    {
        $line = BBCode::parse('[bold][italic]text[/][/]', $this->theme);

        self::assertCount(1, $line->spans);
        self::assertSame('text', $line->spans[0]->content);
        self::assertTrue($line->spans[0]->style->hasModifier(Modifier::Bold));
        self::assertTrue($line->spans[0]->style->hasModifier(Modifier::Italic));
    }

    #[Test]
    public function namedSemanticTagMatchesThemeForeground(): void
    {
        $line = BBCode::parse('[accent]text[/]', $this->theme);

        self::assertCount(1, $line->spans);
        self::assertSame('text', $line->spans[0]->content);
        self::assertNotNull($line->spans[0]->style->foreground);
        self::assertTrue($line->spans[0]->style->foreground->equals(Color::hex('#88ccff')));
    }

    #[Test]
    public function hexColorTagMatchesExactColor(): void
    {
        $line = BBCode::parse('[#ff0000]red[/]', $this->theme);

        self::assertCount(1, $line->spans);
        self::assertSame('red', $line->spans[0]->content);
        self::assertNotNull($line->spans[0]->style->foreground);
        self::assertTrue($line->spans[0]->style->foreground->equals(Color::hex('#ff0000')));
    }

    #[Test]
    public function backgroundWithOn(): void
    {
        $line = BBCode::parse('[on #333333]text[/]', $this->theme);

        self::assertCount(1, $line->spans);
        self::assertNotNull($line->spans[0]->style->background);
    }

    #[Test]
    public function combinedTokens(): void
    {
        $line = BBCode::parse('[bold accent]text[/]', $this->theme);

        self::assertCount(1, $line->spans);
        self::assertTrue($line->spans[0]->style->hasModifier(Modifier::Bold));
        self::assertNotNull($line->spans[0]->style->foreground);
    }

    #[Test]
    public function closeRestoresOuterStyle(): void
    {
        $line = BBCode::parse('outer[bold]inner[/]rest', $this->theme);

        self::assertCount(3, $line->spans);
        self::assertSame('outer', $line->spans[0]->content);
        self::assertSame('inner', $line->spans[1]->content);
        self::assertSame('rest', $line->spans[2]->content);
        self::assertTrue($line->spans[0]->style->equals($line->spans[2]->style));
        self::assertFalse($line->spans[1]->style->isEmpty);
    }

    #[Test]
    public function escapeBracket(): void
    {
        $line = BBCode::parse('a [[b]] c', $this->theme);

        $text = implode('', array_map(static fn ($s) => $s->content, $line->spans));
        self::assertSame('a [b]] c', $text);
    }

    #[Test]
    public function mixedPlainAndStyled(): void
    {
        $line = BBCode::parse('hello [bold]world[/] foo', $this->theme);

        self::assertCount(3, $line->spans);
        self::assertSame('hello ', $line->spans[0]->content);
        self::assertSame('world', $line->spans[1]->content);
        self::assertSame(' foo', $line->spans[2]->content);
        self::assertTrue($line->spans[1]->style->hasModifier(Modifier::Bold));
    }

    #[Test]
    public function allSixModifiers(): void
    {
        $modifiers = [
            'bold' => Modifier::Bold,
            'dim' => Modifier::Dim,
            'italic' => Modifier::Italic,
            'underline' => Modifier::Underline,
            'reverse' => Modifier::Reverse,
            'strikethrough' => Modifier::Strikethrough,
        ];

        foreach ($modifiers as $tag => $modifier) {
            $line = BBCode::parse("[{$tag}]x[/]", $this->theme);
            self::assertTrue(
                $line->spans[0]->style->hasModifier($modifier),
                "Expected modifier {$tag} to be set",
            );
        }
    }

    #[Test]
    public function allSemanticNames(): void
    {
        $names = [
            'default', 'muted', 'subtle', 'bright', 'accent', 'success',
            'warning', 'error', 'info', 'hint', 'active',
        ];

        foreach ($names as $name) {
            $line = BBCode::parse("[{$name}]x[/]", $this->theme);
            self::assertFalse(
                $line->spans[0]->style->isEmpty,
                "Expected semantic name '{$name}' to produce a non-empty style",
            );
        }
    }

    #[Test]
    public function nestedInheritance(): void
    {
        $line = BBCode::parse('[bold][accent]text[/]still bold[/]', $this->theme);

        self::assertCount(2, $line->spans);
        self::assertSame('text', $line->spans[0]->content);
        self::assertSame('still bold', $line->spans[1]->content);
        self::assertTrue($line->spans[0]->style->hasModifier(Modifier::Bold));
        self::assertTrue($line->spans[1]->style->hasModifier(Modifier::Bold));
        self::assertNotNull($line->spans[0]->style->foreground);
    }

    #[Test]
    public function unclosedTagTreatsRemainderAsStyled(): void
    {
        $line = BBCode::parse('[bold]open', $this->theme);

        self::assertCount(1, $line->spans);
        self::assertSame('open', $line->spans[0]->content);
        self::assertTrue($line->spans[0]->style->hasModifier(Modifier::Bold));
    }

    #[Test]
    public function emptyTagFlushesSpanWithoutStyleChange(): void
    {
        $line = BBCode::parse('text[]more', $this->theme);

        self::assertCount(2, $line->spans);
        self::assertSame('text', $line->spans[0]->content);
        self::assertSame('more', $line->spans[1]->content);
        self::assertTrue($line->spans[0]->style->equals($line->spans[1]->style));
    }

    #[Test]
    public function onWithSemanticNameSetsBackground(): void
    {
        $line = BBCode::parse('[on accent]text[/]', $this->theme);

        self::assertCount(1, $line->spans);
        self::assertNotNull($line->spans[0]->style->background);
    }

    #[Test]
    public function onAloneIsIgnored(): void
    {
        $line = BBCode::parse('[on]text[/]', $this->theme);

        $text = implode('', array_map(static fn ($s) => $s->content, $line->spans));
        self::assertSame('text', $text);
    }

    #[Test]
    public function hexForegroundWithOnBackground(): void
    {
        $line = BBCode::parse('[#ff0000 on #333333]text[/]', $this->theme);

        self::assertCount(1, $line->spans);
        self::assertNotNull($line->spans[0]->style->foreground);
        self::assertNotNull($line->spans[0]->style->background);
    }

    #[Test]
    public function bareCloseBracketInText(): void
    {
        $line = BBCode::parse('a ] b', $this->theme);

        self::assertCount(1, $line->spans);
        self::assertSame('a ] b', $line->spans[0]->content);
    }

    #[Test]
    public function openBracketAtEndOfString(): void
    {
        $line = BBCode::parse('text[', $this->theme);

        $text = implode('', array_map(static fn ($s) => $s->content, $line->spans));
        self::assertSame('text', $text);
    }

    #[Test]
    public function leadingCloseTagDoesNotCrash(): void
    {
        $line = BBCode::parse('[/]text', $this->theme);

        self::assertCount(1, $line->spans);
        self::assertSame('text', $line->spans[0]->content);
    }

    #[Test]
    public function deeplyNestedModifiersUnwindCorrectly(): void
    {
        $line = BBCode::parse('[bold][italic][underline]deep[/]mid[/]outer[/]plain', $this->theme);

        self::assertCount(4, $line->spans);
        self::assertSame('deep', $line->spans[0]->content);
        self::assertTrue($line->spans[0]->style->hasModifier(Modifier::Bold));
        self::assertTrue($line->spans[0]->style->hasModifier(Modifier::Italic));
        self::assertTrue($line->spans[0]->style->hasModifier(Modifier::Underline));

        self::assertSame('mid', $line->spans[1]->content);
        self::assertTrue($line->spans[1]->style->hasModifier(Modifier::Bold));
        self::assertTrue($line->spans[1]->style->hasModifier(Modifier::Italic));
        self::assertFalse($line->spans[1]->style->hasModifier(Modifier::Underline));

        self::assertSame('outer', $line->spans[2]->content);
        self::assertTrue($line->spans[2]->style->hasModifier(Modifier::Bold));
        self::assertFalse($line->spans[2]->style->hasModifier(Modifier::Italic));

        self::assertSame('plain', $line->spans[3]->content);
        self::assertTrue($line->spans[3]->style->isEmpty);
    }

    #[Test]
    public function partialTagAtEndLosesTagContent(): void
    {
        $line = BBCode::parse('hello[bold', $this->theme);

        $text = implode('', array_map(static fn ($s) => $s->content, $line->spans));
        self::assertSame('hello', $text);
    }

    protected function setUp(): void
    {
        $this->theme = Theme::default();
    }
}
