<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Screens;

use Phalanx\Harness\Ui\AppStore;
use Phalanx\Harness\Ui\Render\ConversationEventFormatter;
use Phalanx\Harness\Ui\Render\ConversationEventRenderPolicy;
use Phalanx\Harness\Ui\Render\MarkdownRenderer;
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

final class ConversationBlockDetailScreen implements Screen, HasStatusBar, HasFocusables, DeclaresBindings, NormalModeHandler
{
    private MarkdownRenderer $markdown;

    public function __construct(
        private(set) AppStore $store,
    ) {
        $this->markdown = new MarkdownRenderer();
    }

    public function __invoke(ScreenContext $ctx): Renderable
    {
        $turn = $this->store->workspaceView->selectedTurn($this->store->conversation);

        if ($turn === null) {
            return text('  No conversation block selected.');
        }

        $rows = [
            self::row(Line::from(
                Span::styled('  ── Conversation Block ─────────────────────', self::headerStyle()),
            )),
            self::blank(),
            self::row(Line::from(
                Span::styled('  you: ', TextStyle::new()->fg(Color::indexed(255))->bold()),
                Span::styled($turn->userText, TextStyle::new()->fg(Color::indexed(252))),
            )),
            self::blank(),
        ];

        if ($turn->hasAssistantText()) {
            $rows[] = self::row(Line::from(
                Span::styled('  assistant:', TextStyle::new()->fg(Color::indexed(252))->bold()),
            ));
            $rows = [...$rows, ...$this->markdown->render($turn->assistantText(), max(20, $ctx->width - 2), '    ')];
        }

        if ($turn->projectionEvents() !== []) {
            $rows[] = self::blank();
            $rows[] = self::row(Line::from(
                Span::styled('  events:', TextStyle::new()->fg(Color::indexed(252))->bold()),
            ));

            foreach ($turn->projectionEvents() as $event) {
                $rows[] = self::row(Line::from(
                    Span::styled('    ' . ConversationEventRenderPolicy::marker($event) . ' ', ConversationEventRenderPolicy::style($event->projection->severity)),
                    Span::styled(ConversationEventFormatter::detail($event), ConversationEventRenderPolicy::style($event->projection->severity)),
                ));
            }
        }

        return column(...array_slice($rows, 0, max(1, $ctx->height)));
    }

    public function statusBar(): Renderable
    {
        return text(
            Line::from(
                Span::styled('  Esc', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(' back', TextStyle::new()->fg(Color::indexed(250))),
                self::pipe(),
                Span::styled('^C', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(' quit', TextStyle::new()->fg(Color::indexed(250))),
            ),
            TdomStyle::of(size: Size::fixed(1)),
        );
    }

    /** @return list<array{string, Focusable}> */
    public function focusables(): array
    {
        return [['conversation-detail', $this]];
    }

    /** @return list<Binding> */
    public function bindings(): array
    {
        return [
            Binding::key(Key::Escape)->back()->label('back'),
        ];
    }

    public function handleNormalKey(KeyEvent $event): bool
    {
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
}
