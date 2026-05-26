<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Overlay;

use Phalanx\Harness\Ui\Keymap\KeymapEntry;
use Phalanx\Harness\Ui\Keymap\UiKeymap;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Contract\Component;
use Phalanx\Theatron\Contract\HasOverlayFrame;
use Phalanx\Theatron\Contract\HasStatusBar;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\NormalModeHandler;
use Phalanx\Theatron\Layout\Padding;
use Phalanx\Theatron\Layout\Size;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Overlay\OverlayFrame;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style as TdomStyle;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

use function Phalanx\Theatron\Ui\column;
use function Phalanx\Theatron\Ui\panel;
use function Phalanx\Theatron\Ui\text;

final class KeymapOverlay implements Component, HasOverlayFrame, HasStatusBar, NormalModeHandler
{
    private bool $closed = false;

    public function __construct(
        private Navigator $navigator,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        $panelBg = $ctx->theme->overlaySurface;

        return panel(
            'Keymap',
            column(...self::rows(UiKeymap::entries(), $panelBg)),
            TdomStyle::of(
                padding: Padding::all(1),
                color: $ctx->theme->overlayBorder,
                background: $panelBg,
            ),
        );
    }

    public function overlayFrame(Rect $bounds): OverlayFrame
    {
        return OverlayFrame::rightPanel($bounds);
    }

    public function statusBar(): Renderable
    {
        return text('[muted]Esc[/] close  [muted]q[/] close');
    }

    public function handleNormalKey(KeyEvent $event): bool
    {
        if ($this->closed) {
            return true;
        }

        if ($event->is(Key::Escape) || $event->is('q')) {
            $this->closed = true;
            $this->navigator->dismiss();

            return true;
        }

        return true;
    }

    /**
     * @param list<KeymapEntry> $entries
     * @return list<Renderable>
     */
    private static function rows(array $entries, Color $background): array
    {
        $rows = [];
        $section = null;

        foreach ($entries as $entry) {
            if ($entry->section !== $section) {
                if ($rows !== []) {
                    $rows[] = self::fixedText('', $background);
                }

                $rows[] = self::sectionText($entry->section, $background);
                $section = $entry->section;
            }

            $rows[] = self::entryText($entry, $background);
        }

        return $rows;
    }

    private static function sectionText(string $section, Color $background): Renderable
    {
        return self::fixedText(
            Line::styled(
                $section,
                TextStyle::new()
                    ->fg(Color::indexed(252))
                    ->bg($background)
                    ->bold(),
            ),
            $background,
        );
    }

    private static function entryText(KeymapEntry $entry, Color $background): Renderable
    {
        return self::fixedText(
            Line::from(
                Span::styled(
                    sprintf('%-13s', $entry->combo),
                    TextStyle::new()->fg(Color::indexed(245))->bg($background),
                ),
                Span::styled(
                    $entry->label,
                    TextStyle::new()->fg(Color::indexed(250))->bg($background),
                ),
            ),
            $background,
        );
    }

    private static function fixedText(string|Line $content, Color $background): Renderable
    {
        return text(
            $content,
            TdomStyle::of(
                size: Size::fixed(1),
                background: $background,
            ),
        );
    }
}
