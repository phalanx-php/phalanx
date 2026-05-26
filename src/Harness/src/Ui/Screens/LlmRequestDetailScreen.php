<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Screens;

use Phalanx\Harness\Ui\AppStore;
use Phalanx\Harness\Ui\Render\CodeHighlighter;
use Phalanx\Harness\Ui\Slices\LlmRequestEntry;
use Phalanx\Theatron\Binding\Binding;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\DeclaresBindings;
use Phalanx\Theatron\Contract\Focusable;
use Phalanx\Theatron\Contract\HasFocusables;
use Phalanx\Theatron\Contract\HasStatusBar;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\NormalModeHandler;
use Phalanx\Theatron\Layout\Size;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style as TdomStyle;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

use function Phalanx\Theatron\Ui\column;
use function Phalanx\Theatron\Ui\text;

final class LlmRequestDetailScreen implements Screen, HasStatusBar, HasFocusables, DeclaresBindings, NormalModeHandler
{
    private CodeHighlighter $highlighter;

    public function __construct(
        private(set) AppStore $store,
    ) {
        $this->highlighter = new CodeHighlighter();
    }

    public function __invoke(ScreenContext $ctx): Renderable
    {
        $entry = $this->store->requests->selected();

        if ($entry === null) {
            return text('  No request selected.');
        }

        $rows = array_slice($this->rows($entry), $this->store->requests->detailScrollOffset, 40);

        return column(...$rows);
    }

    public function statusBar(): Renderable
    {
        return text(
            Line::from(
                Span::styled('  Up', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(' scroll', TextStyle::new()->fg(Color::indexed(250))),
                self::pipe(),
                Span::styled('Dn', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(' scroll', TextStyle::new()->fg(Color::indexed(250))),
                self::pipe(),
                Span::styled('^C', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(' quit', TextStyle::new()->fg(Color::indexed(250))),
                self::pipe(),
                Span::styled('Esc', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(' back', TextStyle::new()->fg(Color::indexed(250))),
            ),
            TdomStyle::of(size: Size::fixed(1)),
        );
    }

    /** @return list<array{string, Focusable}> */
    public function focusables(): array
    {
        return [['detail', $this]];
    }

    /** @return list<Binding> */
    public function bindings(): array
    {
        return [
            Binding::key(Key::Up)->label('scroll'),
            Binding::key(Key::Down)->label('scroll'),
            Binding::key(Key::Escape)->back()->label('back'),
        ];
    }

    public function handleNormalKey(KeyEvent $event): bool
    {
        if ($event->is(Key::Up)) {
            $this->store->requests = $this->store->requests->scrollDetailUp();

            return true;
        }

        if ($event->is(Key::Down)) {
            $this->store->requests = $this->store->requests->scrollDetailDown();

            return true;
        }

        return false;
    }

    private static function row(Line $line): Renderable
    {
        return text($line, TdomStyle::of(size: Size::fixed(1)));
    }

    private static function blank(): Renderable
    {
        return self::row(Line::plain(''));
    }

    private static function pipe(): Span
    {
        return Span::styled('  │  ', TextStyle::new()->fg(Color::indexed(238)));
    }

    private static function headerStyle(): TextStyle
    {
        return TextStyle::new()->fg(Color::indexed(252))->bold();
    }

    private static function labelStyle(): TextStyle
    {
        return TextStyle::new()->fg(Color::indexed(245));
    }

    private static function valueStyle(): TextStyle
    {
        return TextStyle::new()->fg(Color::indexed(250));
    }

    private static function dimStyle(): TextStyle
    {
        return TextStyle::new()->fg(Color::indexed(242));
    }

    /** @return list<Renderable> */
    private function rows(LlmRequestEntry $entry): array
    {
        $status = $entry->error !== null ? 'ERR: ' . $entry->error : (($entry->status ?? 'pending') . ' OK');
        $time = $entry->elapsedMs !== null ? number_format($entry->elapsedMs, 1) . 'ms' : '...';
        $tokens = $entry->tokenCount !== null ? $entry->tokenCount . ' tokens' : '';
        $rows = [
            self::row(Line::from(
                Span::styled("  ── {$entry->method} {$entry->path} ─────────────────────────", self::headerStyle()),
            )),
            self::blank(),
            self::row(Line::from(
                Span::styled('  Status: ', self::labelStyle()),
                Span::styled($status, self::valueStyle()),
                Span::styled("  Time: {$time}", self::labelStyle()),
                Span::styled("  {$tokens}", self::valueStyle()),
            )),
            self::blank(),
            self::row(Line::from(Span::styled('  Request Body', self::headerStyle()))),
            self::row(Line::from(Span::styled('  ' . str_repeat('─', 40), self::dimStyle()))),
        ];

        $rows = [...$rows, ...$this->bodyRows($entry->requestBody)];
        $rows[] = self::blank();
        $rows[] = self::row(Line::from(Span::styled('  Response Body', self::headerStyle())));
        $rows[] = self::row(Line::from(Span::styled('  ' . str_repeat('─', 40), self::dimStyle())));

        return [...$rows, ...$this->bodyRows($entry->responseBody)];
    }

    /** @return list<Renderable> */
    private function bodyRows(?string $body): array
    {
        if ($body === null || $body === '') {
            return [self::row(Line::from(Span::styled('    (empty)', self::dimStyle())))];
        }

        $decoded = json_decode($body, true);
        $pretty = json_last_error() === JSON_ERROR_NONE
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $body;
        $rows = [];

        foreach ($this->highlighter->highlight((string) $pretty, 'json') as $line) {
            $rows[] = self::row(Line::from(Span::plain('    '), ...$line->spans));
        }

        return $rows;
    }
}
