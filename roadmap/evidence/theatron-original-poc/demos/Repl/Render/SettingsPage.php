<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Render;

use Phalanx\Theatron\Demos\Repl\Slice\SettingsSlice;
use Phalanx\Theatron\Demos\Repl\Slice\SettingsTab;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

class SettingsPage
{
    public function render(Ui $ui, SettingsSlice $settings, int $width, int $height, ?string $modelName = null): Renderable
    {
        $rows = [];

        $rows[] = self::row($ui, Line::from(
            Span::styled('  Settings', TextStyle::new()->fg(Color::indexed(255))->bold()),
        ));
        $rows[] = self::divider($ui, $width);

        $rows[] = self::row($ui, self::renderTabRail($settings, $width));
        $rows[] = self::divider($ui, $width);
        $rows[] = self::row($ui, Line::from(Span::plain('')));

        $items = $settings->activeTab->items($modelName);

        foreach ($items as $i => $item) {
            $selected = $i === $settings->selectedItem;
            $enabled = $settings->isEnabled($settings->activeTab, $i);
            $rows[] = self::row($ui, self::renderItem($item, $selected, $enabled, $width));
        }

        $rows[] = self::row($ui, Line::from(Span::plain('')));
        $rows[] = self::divider($ui, $width);
        $rows[] = self::row($ui, Line::from(
            Span::styled(
                "  \u{2190}\u{2192}:tab  \u{2191}\u{2193}:item  Space:toggle  Esc:back",
                TextStyle::new()->fg(Color::indexed(244)),
            ),
        ));

        $visible = array_slice($rows, 0, $height);

        return new ColumnElement($visible);
    }

    private static function renderTabRail(SettingsSlice $settings, int $width): Line
    {
        $tabs = SettingsTab::cases();
        $spans = [Span::plain('  ')];
        $usedWidth = 2;

        foreach ($tabs as $tab) {
            $label = $tab->value;
            $labelWidth = mb_strlen($label) + 2;

            if ($usedWidth + $labelWidth > $width - 3) {
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
    private static function renderItem(array $item, bool $selected, bool $enabled, int $width): Line
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

    private static function divider(Ui $ui, int $width): Renderable
    {
        return self::row($ui, Line::from(
            Span::styled(str_repeat("\u{2500}", $width), TextStyle::new()->fg(Color::indexed(236))),
        ));
    }

    private static function row(Ui $ui, Line $line): Renderable
    {
        return $ui->text($line, style: Style::of(size: Size::fixed(1)));
    }
}
