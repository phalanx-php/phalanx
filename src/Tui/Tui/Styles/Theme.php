<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Styles;

use Phalanx\Tui\Tui\Styles\Border;
use Phalanx\Tui\Tui\Styles\Color;
use Phalanx\Tui\Tui\Styles\Style as AnsiStyle;
use Phalanx\Tui\Tui\Tdom\Style as TdomStyle;

final class Theme
{
    private AnsiStyle $boldStyle;
    private AnsiStyle $dimStyle;
    private AnsiStyle $italicStyle;
    private AnsiStyle $underlineStyle;
    private AnsiStyle $reverseStyle;
    private AnsiStyle $strikethroughStyle;

    /** @var array<string, AnsiStyle> */
    private array $customTags = [];

    private function __construct(
        private(set) Color $fg,
        private(set) Color $bg,
        private(set) Color $surface,
        private(set) Color $overlaySurface,
        private(set) Color $overlayBackdrop,
        private(set) Color $border,
        private(set) Color $overlayBorder,
        private(set) Color $highlight,
        private(set) AnsiStyle $default,
        private(set) AnsiStyle $muted,
        private(set) AnsiStyle $subtle,
        private(set) AnsiStyle $bright,
        private(set) AnsiStyle $accent,
        private(set) AnsiStyle $success,
        private(set) AnsiStyle $warning,
        private(set) AnsiStyle $error,
        private(set) AnsiStyle $info,
        private(set) AnsiStyle $hint,
        private(set) AnsiStyle $active,
        private(set) TdomStyle $panel,
        private(set) TdomStyle $input,
    ) {
        $this->boldStyle = AnsiStyle::new()->bold();
        $this->dimStyle = AnsiStyle::new()->dim();
        $this->italicStyle = AnsiStyle::new()->italic();
        $this->underlineStyle = AnsiStyle::new()->underline();
        $this->reverseStyle = AnsiStyle::new()->reverse();
        $this->strikethroughStyle = AnsiStyle::new()->strikethrough();
    }

    public static function default(): self
    {
        return new self(
            fg: Color::hex('#e0e0e0'),
            bg: Color::hex('#1a1a1a'),
            surface: Color::hex('#2a2a2a'),
            overlaySurface: Color::hex('#111111'),
            overlayBackdrop: Color::hex('#050505'),
            border: Color::hex('#404040'),
            overlayBorder: Color::hex('#545454'),
            highlight: Color::hex('#333333'),
            default: AnsiStyle::new()->fg('#e0e0e0'),
            muted: AnsiStyle::new()->fg('#707070'),
            subtle: AnsiStyle::new()->fg('#909090'),
            bright: AnsiStyle::new()->fg('#ffffff')->bold(),
            accent: AnsiStyle::new()->fg('#88ccff'),
            success: AnsiStyle::new()->fg('#77cc77'),
            warning: AnsiStyle::new()->fg('#ccaa55'),
            error: AnsiStyle::new()->fg('#cc6666'),
            info: AnsiStyle::new()->fg('#88aacc'),
            hint: AnsiStyle::new()->fg('#606060'),
            active: AnsiStyle::new()->fg('#ffffff')->bg('#333333'),
            panel: TdomStyle::of(border: Border::Single, color: Color::hex('#404040')),
            input: TdomStyle::of(border: Border::Rounded, background: Color::hex('#2a2a2a')),
        );
    }

    /** @param array<string, AnsiStyle> $tags */
    public function withTags(array $tags): self
    {
        $clone = clone $this;

        foreach ($tags as $name => $style) {
            $clone->customTags[strtolower($name)] = $style;
        }

        return $clone;
    }

    public function resolve(string $name): ?AnsiStyle
    {
        $key = strtolower($name);

        return $this->customTags[$key] ?? match ($key) {
            'default' => $this->default,
            'muted' => $this->muted,
            'subtle' => $this->subtle,
            'bright' => $this->bright,
            'accent' => $this->accent,
            'success' => $this->success,
            'warning' => $this->warning,
            'error' => $this->error,
            'info' => $this->info,
            'hint' => $this->hint,
            'active' => $this->active,
            'bold' => $this->boldStyle,
            'dim' => $this->dimStyle,
            'italic' => $this->italicStyle,
            'underline' => $this->underlineStyle,
            'reverse' => $this->reverseStyle,
            'strikethrough' => $this->strikethroughStyle,
            default => null,
        };
    }
}
