<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Styles;

use Phalanx\Tui\Tui\Styles\Border;
use Phalanx\Tui\Tui\Styles\Color;
use Phalanx\Tui\Tui\Styles\Modifier;
use Phalanx\Tui\Tui\Styles\Style as AnsiStyle;
use Phalanx\Tui\Tui\Styles\Theme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    #[Test]
    public function surfaceColorsPinned(): void
    {
        $theme = Theme::default();

        self::assertTrue($theme->fg->equals(Color::hex('#e0e0e0')));
        self::assertTrue($theme->bg->equals(Color::hex('#1a1a1a')));
        self::assertTrue($theme->surface->equals(Color::hex('#2a2a2a')));
        self::assertTrue($theme->border->equals(Color::hex('#404040')));
        self::assertTrue($theme->highlight->equals(Color::hex('#333333')));
    }

    #[Test]
    public function textHierarchyForegroundsPinned(): void
    {
        $theme = Theme::default();

        self::assertNotNull($theme->default->foreground);
        self::assertTrue($theme->default->foreground->equals(Color::hex('#e0e0e0')));
        self::assertNotNull($theme->muted->foreground);
        self::assertTrue($theme->muted->foreground->equals(Color::hex('#707070')));
        self::assertNotNull($theme->subtle->foreground);
        self::assertTrue($theme->subtle->foreground->equals(Color::hex('#909090')));
        self::assertNotNull($theme->bright->foreground);
        self::assertTrue($theme->bright->foreground->equals(Color::hex('#ffffff')));
    }

    #[Test]
    public function brightStyleIsBold(): void
    {
        $theme = Theme::default();

        self::assertTrue($theme->bright->hasModifier(Modifier::Bold));
    }

    #[Test]
    public function semanticAccentsPinned(): void
    {
        $theme = Theme::default();

        self::assertNotNull($theme->accent->foreground);
        self::assertTrue($theme->accent->foreground->equals(Color::hex('#88ccff')));
        self::assertNotNull($theme->success->foreground);
        self::assertTrue($theme->success->foreground->equals(Color::hex('#77cc77')));
        self::assertNotNull($theme->warning->foreground);
        self::assertTrue($theme->warning->foreground->equals(Color::hex('#ccaa55')));
        self::assertNotNull($theme->error->foreground);
        self::assertTrue($theme->error->foreground->equals(Color::hex('#cc6666')));
        self::assertNotNull($theme->info->foreground);
        self::assertTrue($theme->info->foreground->equals(Color::hex('#88aacc')));
        self::assertNotNull($theme->hint->foreground);
        self::assertTrue($theme->hint->foreground->equals(Color::hex('#606060')));
    }

    #[Test]
    public function activeStylePinned(): void
    {
        $theme = Theme::default();

        self::assertNotNull($theme->active->foreground);
        self::assertTrue($theme->active->foreground->equals(Color::hex('#ffffff')));
        self::assertNotNull($theme->active->background);
        self::assertTrue($theme->active->background->equals(Color::hex('#333333')));
    }

    #[Test]
    public function resolveReturnsMatchingSemanticStyle(): void
    {
        $theme = Theme::default();

        $accent = $theme->resolve('accent');
        self::assertNotNull($accent);
        self::assertTrue($accent->equals($theme->accent));

        $success = $theme->resolve('success');
        self::assertNotNull($success);
        self::assertTrue($success->equals($theme->success));

        $error = $theme->resolve('error');
        self::assertNotNull($error);
        self::assertTrue($error->equals($theme->error));
    }

    #[Test]
    public function resolveReturnsNullForUnknown(): void
    {
        $theme = Theme::default();

        self::assertNull($theme->resolve('nonexistent'));
        self::assertNull($theme->resolve('olympus'));
        self::assertNull($theme->resolve(''));
    }

    #[Test]
    public function resolveIsCaseInsensitive(): void
    {
        $theme = Theme::default();

        self::assertNotNull($theme->resolve('ACCENT'));
        self::assertNotNull($theme->resolve('Accent'));
        self::assertNotNull($theme->resolve('SUCCESS'));
        self::assertNotNull($theme->resolve('Bold'));
    }

    #[Test]
    public function resolveAllSixModifiers(): void
    {
        $theme = Theme::default();

        $modifiers = [
            'bold' => Modifier::Bold,
            'dim' => Modifier::Dim,
            'italic' => Modifier::Italic,
            'underline' => Modifier::Underline,
            'reverse' => Modifier::Reverse,
            'strikethrough' => Modifier::Strikethrough,
        ];

        foreach ($modifiers as $name => $modifier) {
            $resolved = $theme->resolve($name);
            self::assertNotNull($resolved, "resolve('{$name}') returned null");
            self::assertTrue(
                $resolved->hasModifier($modifier),
                "resolve('{$name}') missing expected modifier",
            );
        }
    }

    #[Test]
    public function layoutPanelHasSingleBorder(): void
    {
        $theme = Theme::default();

        self::assertSame(Border::Single, $theme->panel->border);
        self::assertNotNull($theme->panel->color);
        self::assertTrue($theme->panel->color->equals(Color::hex('#404040')));
    }

    #[Test]
    public function layoutInputHasRoundedBorder(): void
    {
        $theme = Theme::default();

        self::assertSame(Border::Rounded, $theme->input->border);
        self::assertNotNull($theme->input->background);
        self::assertTrue($theme->input->background->equals(Color::hex('#2a2a2a')));
    }

    #[Test]
    public function resolveReturnsSameInstanceForModifiers(): void
    {
        $theme = Theme::default();

        self::assertSame($theme->resolve('bold'), $theme->resolve('bold'));
        self::assertSame($theme->resolve('italic'), $theme->resolve('italic'));
    }

    #[Test]
    public function withTagsEmptyPreservesBuiltins(): void
    {
        $original = Theme::default();
        $themed = $original->withTags([]);

        self::assertNotSame($original, $themed);

        $accent = $themed->resolve('accent');
        self::assertNotNull($accent);
        self::assertTrue($accent->equals($original->accent));

        $bold = $themed->resolve('bold');
        self::assertNotNull($bold);
        self::assertTrue($bold->hasModifier(Modifier::Bold));
    }

    #[Test]
    public function withTagsCustomTagResolves(): void
    {
        $style = AnsiStyle::new()->fg('#77cc77')->bold();
        $theme = Theme::default()->withTags(['agent' => $style]);

        $resolved = $theme->resolve('agent');
        self::assertNotNull($resolved);
        self::assertTrue($resolved->equals($style));
    }

    #[Test]
    public function withTagsCustomTagIsCaseInsensitive(): void
    {
        $style = AnsiStyle::new()->fg('#ff6666');
        $theme = Theme::default()->withTags(['danger' => $style]);

        self::assertNotNull($theme->resolve('DANGER'));
        self::assertNotNull($theme->resolve('Danger'));

        $resolved = $theme->resolve('danger');
        self::assertNotNull($resolved);
        self::assertTrue($resolved->equals($style));
    }

    #[Test]
    public function withTagsOverridesBuiltIn(): void
    {
        $custom = AnsiStyle::new()->fg('#ff0000')->bold();
        $theme = Theme::default()->withTags(['accent' => $custom]);

        $resolved = $theme->resolve('accent');
        self::assertNotNull($resolved);
        self::assertTrue($resolved->equals($custom));
        self::assertFalse($resolved->equals(Theme::default()->accent));
    }

    #[Test]
    public function withTagsDoesNotMutateOriginal(): void
    {
        $original = Theme::default();
        $original->withTags(['agent' => AnsiStyle::new()->fg('#77cc77')]);

        self::assertNull($original->resolve('agent'));
    }

    #[Test]
    public function withTagsChainingAccumulatesTags(): void
    {
        $agentStyle = AnsiStyle::new()->fg('#77cc77');
        $dangerStyle = AnsiStyle::new()->fg('#ff6666');

        $theme = Theme::default()
            ->withTags(['agent' => $agentStyle])
            ->withTags(['danger' => $dangerStyle]);

        self::assertNotNull($theme->resolve('agent'));
        self::assertNotNull($theme->resolve('danger'));
        self::assertTrue($theme->resolve('agent')->equals($agentStyle));
        self::assertTrue($theme->resolve('danger')->equals($dangerStyle));
    }
}
