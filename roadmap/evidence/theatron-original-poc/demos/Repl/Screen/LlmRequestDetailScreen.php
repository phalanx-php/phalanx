<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Screen;

use Phalanx\Theatron\Demos\Repl\Input\HotkeyBinding;
use Phalanx\Theatron\Demos\Repl\Input\HotkeyContext;
use Phalanx\Theatron\Demos\Repl\Slice\LlmRequestEntry;
use Phalanx\Theatron\Demos\Repl\Slice\LlmRequestSlice;
use Phalanx\Theatron\Highlight\TempestHighlighter;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

final class LlmRequestDetailScreen implements Screen
{
    public string $name { get => 'llm-request-detail'; }

    public function isOverlay(): bool { return false; }

    /** @return list<HotkeyBinding> */
    public function bindings(): array
    {
        return [
            new HotkeyBinding(Key::Up, label: 'Up:scroll', handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(LlmRequestSlice::class, static fn(LlmRequestSlice $s): LlmRequestSlice => $s->scrollDetailUp());
            }),
            new HotkeyBinding(Key::Down, label: 'Dn:scroll', handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(LlmRequestSlice::class, static fn(LlmRequestSlice $s): LlmRequestSlice => $s->scrollDetailDown());
            }),
        ];
    }

    public function handleInput(KeyEvent $event, HotkeyContext $ctx): bool
    {
        return false;
    }

    public function render(Ui $ui, HotkeyContext $ctx, int $width, int $height): Renderable
    {
        $requests = $ctx->lens->handle(LlmRequestSlice::class)->value;
        $entry = $requests->focused();

        if ($entry === null) {
            return $ui->text(Line::plain('  No request selected.'), Style::of(size: Size::fixed(1)));
        }

        $rows = self::buildDetailRows($ui, $entry, $width);
        $visible = array_slice($rows, $requests->detailScrollOffset, $height);

        return new ColumnElement($visible, Style::of(size: Size::fill()));
    }

    /** @return list<Renderable> */
    private static function buildDetailRows(Ui $ui, LlmRequestEntry $entry, int $width): array
    {
        $rows = [];
        $h = TextStyle::new()->fg(Color::indexed(252))->bold();
        $l = TextStyle::new()->fg(Color::indexed(245));
        $v = TextStyle::new()->fg(Color::indexed(250));
        $dim = TextStyle::new()->fg(Color::indexed(242));

        $statusStr = match (true) {
            $entry->error !== null => 'ERR: ' . $entry->error,
            $entry->status !== null => (string) $entry->status . ' OK',
            default => 'pending...',
        };

        $timeStr = $entry->elapsedMs !== null ? number_format($entry->elapsedMs, 1) . 'ms' : '...';
        $tokStr = $entry->tokenCount !== null ? $entry->tokenCount . ' tokens' : '';

        $sepWidth = min($width - 4, 60);
        $rows[] = self::row($ui, Line::from(
            Span::styled('  ' . str_repeat("\u{2500}", 2) . " {$entry->method} {$entry->path} " . str_repeat("\u{2500}", max(0, $sepWidth - mb_strlen($entry->method) - mb_strlen($entry->path) - 5)), TextStyle::new()->fg(Color::indexed(252))->bold()),
        ));
        $rows[] = self::row($ui, Line::from(Span::plain('')));
        $rows[] = self::row($ui, Line::from(
            Span::styled('  Status: ', $l),
            Span::styled($statusStr, $v),
            Span::styled("  Time: {$timeStr}", $l),
            Span::styled("  {$tokStr}", $v),
        ));
        $rows[] = self::row($ui, Line::from(Span::plain('')));

        $rows[] = self::row($ui, Line::from(Span::styled('  Request Body', $h)));
        $rows[] = self::row($ui, Line::from(Span::styled('  ' . str_repeat("\u{2500}", 40), $dim)));
        self::renderBody($ui, $entry->requestBody, $rows);

        $rows[] = self::row($ui, Line::from(Span::plain('')));
        $rows[] = self::row($ui, Line::from(Span::styled('  Response Body', $h)));
        $rows[] = self::row($ui, Line::from(Span::styled('  ' . str_repeat("\u{2500}", 40), $dim)));
        self::renderBody($ui, $entry->responseBody, $rows);

        return $rows;
    }

    /** @param list<Renderable> &$rows */
    private static function renderBody(Ui $ui, ?string $body, array &$rows): void
    {
        $dim = TextStyle::new()->fg(Color::indexed(242));

        if ($body === null) {
            $rows[] = self::row($ui, Line::from(Span::styled('    (empty)', $dim)));

            return;
        }

        $decoded = json_decode($body, true);
        $pretty = ($decoded !== null || json_last_error() === JSON_ERROR_NONE)
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $body;

        $highlighter = new TempestHighlighter('json');
        $highlighted = $highlighter->highlight(rtrim($pretty, "\n"));

        foreach ($highlighted as $hl) {
            $rows[] = self::row($ui, Line::from(Span::plain('    '), ...$hl->spans));
        }
    }

    private static function row(Ui $ui, Line $line): Renderable
    {
        return $ui->text($line, style: Style::of(size: Size::fixed(1)));
    }
}
