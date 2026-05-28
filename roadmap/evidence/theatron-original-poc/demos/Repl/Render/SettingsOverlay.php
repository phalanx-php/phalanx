<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Render;

use Phalanx\Theatron\Demos\Repl\Slice\SettingsSlice;
use Phalanx\Theatron\Demos\Repl\Slice\SettingsTab;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

class SettingsOverlay
{
    private const int MIN_CONTENT_ROWS = 4;

    public function render(Ui $ui, SettingsSlice $settings, int $width, int $height): Renderable
    {
        $modalWidth = min($width, 52);
        $innerWidth = $modalWidth - 2;
        [$tl, $tr, $bl, $br, $h, $v] = Border::Rounded->chars();

        $rows = [];

        $title = ' Settings ';
        $titlePad = max(0, $modalWidth - 2 - mb_strlen($title));
        $rows[] = self::row($ui, Line::from(
            Span::styled("{$tl}{$h}" . $title . str_repeat($h, $titlePad) . "{$tr}", TextStyle::new()->fg(Color::indexed(248))->bg(Color::indexed(239))),
        ));

        $rows[] = self::borderRow($ui, $v, $innerWidth, Line::from(Span::plain('')));
        $rows[] = self::borderRow($ui, $v, $innerWidth, self::renderTabRail($settings, $innerWidth));
        $rows[] = self::borderRow($ui, $v, $innerWidth, Line::from(
            Span::styled('  ' . str_repeat("\u{2500}", min($innerWidth - 2, 44)), TextStyle::new()->fg(Color::indexed(243))),
        ));
        $rows[] = self::borderRow($ui, $v, $innerWidth, Line::from(Span::plain('')));

        $items = $settings->activeTab->items();
        $itemCount = count($items);

        foreach ($items as $i => $item) {
            $selected = $i === $settings->selectedItem;
            $enabled = $settings->isEnabled($settings->activeTab, $i);
            $line = self::renderItem($item, $selected, $enabled, $innerWidth);
            $rows[] = self::borderRow($ui, $v, $innerWidth, $line);
        }

        $padCount = max(0, self::MIN_CONTENT_ROWS - $itemCount);

        for ($p = 0; $p < $padCount; $p++) {
            $rows[] = self::borderRow($ui, $v, $innerWidth, Line::from(Span::plain('')));
        }

        $rows[] = self::borderRow($ui, $v, $innerWidth, Line::from(Span::plain('')));
        $rows[] = self::borderRow($ui, $v, $innerWidth, Line::from(
            Span::styled('  ' . str_repeat("\u{2500}", min($innerWidth - 2, 44)), TextStyle::new()->fg(Color::indexed(243))),
        ));
        $rows[] = self::borderRow($ui, $v, $innerWidth, Line::from(
            Span::styled("  \u{2190}\u{2192}:tab  \u{2191}\u{2193}:item  Space:toggle  Esc:close", TextStyle::new()->fg(Color::indexed(244))),
        ));

        $bottomPad = max(0, $modalWidth - 2);
        $rows[] = self::row($ui, Line::from(
            Span::styled("{$bl}" . str_repeat($h, $bottomPad) . "{$br}", TextStyle::new()->fg(Color::indexed(248))->bg(Color::indexed(239))),
        ));

        $visible = array_slice($rows, 0, $height);

        return $ui->column(...$visible);
    }

    private static function renderTabRail(SettingsSlice $settings, int $innerWidth): Line
    {
        $tabs = SettingsTab::cases();
        $spans = [Span::plain('  ')];
        $usedWidth = 2;

        foreach ($tabs as $tab) {
            $label = $tab->value;
            $labelWidth = mb_strlen($label) + 2;

            if ($usedWidth + $labelWidth > $innerWidth - 3) {
                $spans[] = Span::styled(' ...', TextStyle::new()->fg(Color::indexed(242)));
                break;
            }

            if ($tab === $settings->activeTab) {
                $spans[] = Span::styled(" {$label} ", TextStyle::new()->fg(Color::indexed(255))->bold()->underline());
            } else {
                $spans[] = Span::styled(" {$label} ", TextStyle::new()->fg(Color::indexed(245)));
            }

            $usedWidth += $labelWidth;
        }

        return Line::from(...$spans);
    }

    /** @param array{string, string, bool} $item */
    private static function renderItem(array $item, bool $selected, bool $enabled, int $innerWidth): Line
    {
        [$label, $type] = $item;
        $prefix = $selected ? '  > ' : '    ';

        if ($type === 'toggle') {
            $checkbox = $enabled ? '[x]' : '[ ]';
            $text = "{$prefix}{$checkbox} {$label}";
        } else {
            $text = "{$prefix}{$label}";
        }

        $style = $selected
            ? TextStyle::new()->fg(Color::indexed(255))
            : TextStyle::new()->fg(Color::indexed(250));

        return Line::from(Span::styled($text, $style));
    }

    private static function borderRow(Ui $ui, string $v, int $innerWidth, Line $content): Renderable
    {
        $contentText = '';

        foreach ($content->spans as $span) {
            $contentText .= $span->content;
        }

        $pad = max(0, $innerWidth - mb_strlen($contentText));
        $borderStyle = TextStyle::new()->fg(Color::indexed(248))->bg(Color::indexed(239));
        $bgStyle = TextStyle::new()->bg(Color::indexed(239));

        $contentSpans = array_map(
            static fn(Span $s): Span => Span::styled($s->content, $s->style->bg(Color::indexed(239))),
            $content->spans,
        );

        $spans = [
            Span::styled("{$v}", $borderStyle),
            ...$contentSpans,
            Span::styled(str_repeat(' ', $pad), $bgStyle),
            Span::styled("{$v}", $borderStyle),
        ];

        return self::row($ui, Line::from(...$spans));
    }

    private static function row(Ui $ui, Line $line): Renderable
    {
        return $ui->text($line, style: Style::of(size: Size::fixed(1)));
    }
}
