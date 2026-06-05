<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Styles;

use Phalanx\Tui\Core\RenderEnvironment;
use Phalanx\Tui\Styles\Style as AnsiStyle;
use Phalanx\Tui\Styles\BBCode;
use Phalanx\Tui\Styles\Theme;
use Phalanx\Tui\Styles\Line;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Phalanx\Tui\Kit\input;
use function Phalanx\Tui\Kit\panel;
use function Phalanx\Tui\Kit\progress;
use function Phalanx\Tui\Kit\spinner;
use function Phalanx\Tui\Kit\text;

final class UiMarkupTest extends TestCase
{
    #[Test]
    public function panelTitleWithThemeParsesMarkup(): void
    {
        $theme = Theme::default();
        $panel = RenderEnvironment::withTheme(
            $theme,
            static fn() => panel('[accent]Apollo[/]', text('body')),
        );

        self::assertInstanceOf(Line::class, $panel->title);
        self::assertStyledSpan($panel->title, 'Apollo', $theme->accent);
    }

    #[Test]
    public function panelTitleWithoutThemeStaysString(): void
    {
        $panel = panel('[accent]Apollo[/]', text('body'));

        self::assertIsString($panel->title);
        self::assertSame('[accent]Apollo[/]', $panel->title);
    }

    #[Test]
    public function panelTitleWithoutBracketsStaysString(): void
    {
        $panel = RenderEnvironment::withTheme(
            Theme::default(),
            static fn() => panel('Dashboard', text('body')),
        );

        self::assertIsString($panel->title);
        self::assertSame('Dashboard', $panel->title);
    }

    #[Test]
    public function spinnerLabelWithThemeParsesMarkup(): void
    {
        $theme = Theme::default();
        $spinner = RenderEnvironment::withTheme(
            $theme,
            static fn() => spinner('[success]Loading[/]'),
        );

        self::assertInstanceOf(Line::class, $spinner->label);
        self::assertStyledSpan($spinner->label, 'Loading', $theme->success);
    }

    #[Test]
    public function spinnerLabelWithoutThemeStaysString(): void
    {
        $spinner = spinner('[success]Loading[/]');

        self::assertIsString($spinner->label);
        self::assertSame('[success]Loading[/]', $spinner->label);
    }

    #[Test]
    public function spinnerNullLabelStaysNull(): void
    {
        $spinner = RenderEnvironment::withTheme(
            Theme::default(),
            static fn() => spinner(),
        );

        self::assertNull($spinner->label);
    }

    #[Test]
    public function inputPromptWithThemeParsesMarkup(): void
    {
        $theme = Theme::default();
        $input = RenderEnvironment::withTheme(
            $theme,
            static fn() => input(prompt: '[accent]>[/] '),
        );

        self::assertInstanceOf(Line::class, $input->prompt);
        self::assertStyledSpan($input->prompt, '>', $theme->accent);
        self::assertCount(2, $input->prompt->spans);
        self::assertSame(' ', $input->prompt->spans[1]->content);
    }

    #[Test]
    public function inputPromptWithoutThemeStaysString(): void
    {
        $input = input(prompt: '[accent]>[/] ');

        self::assertIsString($input->prompt);
        self::assertSame('[accent]>[/] ', $input->prompt);
    }

    #[Test]
    public function inputPromptWithoutBracketsStaysString(): void
    {
        $input = RenderEnvironment::withTheme(
            Theme::default(),
            static fn() => input(prompt: '> '),
        );

        self::assertIsString($input->prompt);
        self::assertSame('> ', $input->prompt);
    }

    #[Test]
    public function progressLabelWithThemeParsesMarkup(): void
    {
        $theme = Theme::default();
        $boldStyle = $theme->resolve('bold');
        self::assertNotNull($boldStyle);
        $progress = RenderEnvironment::withTheme(
            $theme,
            static fn() => progress(0.5, '[bold]CPU[/]'),
        );

        self::assertInstanceOf(Line::class, $progress->label);
        self::assertStyledSpan($progress->label, 'CPU', $boldStyle);
    }

    #[Test]
    public function progressLabelWithoutThemeStaysString(): void
    {
        $progress = progress(0.5, '[bold]CPU[/]');

        self::assertIsString($progress->label);
        self::assertSame('[bold]CPU[/]', $progress->label);
    }

    #[Test]
    public function progressNullLabelStaysNull(): void
    {
        $progress = RenderEnvironment::withTheme(
            Theme::default(),
            static fn() => progress(0.5),
        );

        self::assertNull($progress->label);
    }

    #[Test]
    public function bbcodeResolvesCustomTagFromWithTags(): void
    {
        $agentStyle = AnsiStyle::new()->fg('#77cc77')->bold();
        $theme = Theme::default()->withTags(['agent' => $agentStyle]);

        $line = BBCode::parse('[agent]Leonidas[/]', $theme);

        self::assertCount(1, $line->spans);
        self::assertTrue($line->spans[0]->style->equals($agentStyle));
        self::assertSame('Leonidas', $line->spans[0]->content);
    }

    #[Test]
    public function uiTextResolvesCustomTagEndToEnd(): void
    {
        $agentStyle = AnsiStyle::new()->fg('#ff6666');
        $theme = Theme::default()->withTags(['danger' => $agentStyle]);

        $text = RenderEnvironment::withTheme(
            $theme,
            static fn() => text('[danger]Alert[/]'),
        );
        $content = $text->content;

        self::assertInstanceOf(Line::class, $content);
        self::assertSame('Alert', $content->spans[0]->content);
    }

    private static function assertStyledSpan(Line $line, string $content, AnsiStyle $style): void
    {
        self::assertNotEmpty($line->spans);
        self::assertSame($content, $line->spans[0]->content);
        self::assertTrue($line->spans[0]->style->equals($style));
    }
}
