<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Screen;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Agent\AgentRuntime;
use Phalanx\Theatron\Binding\Binding;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\DeclaresBindings;
use Phalanx\Theatron\Contract\Focusable;
use Phalanx\Theatron\Contract\HandlesKeySequences;
use Phalanx\Theatron\Contract\HasFocusables;
use Phalanx\Theatron\Contract\HasStatusBar;
use Phalanx\Theatron\Contract\Mountable;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\KeySequenceState;
use Phalanx\Theatron\Kit\Metrics;
use Phalanx\Theatron\Layout\Size;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style as TdomStyle;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Keymap\ComposerChordAction;
use Phalanx\Theatron\Template\Keymap\ComposerChordMap;
use Phalanx\Theatron\Template\Overlay\KeymapOverlay;
use Phalanx\Theatron\Template\Render\ConversationEventFormatter;
use Phalanx\Theatron\Template\Render\ConversationEventRenderPolicy;
use Phalanx\Theatron\Template\Render\MarkdownRenderer;
use Phalanx\Theatron\Template\Slice\ActivityStatus;
use Phalanx\Theatron\Template\Slice\ConversationSlice;
use Phalanx\Theatron\Template\Slice\ConversationTurn;
use Phalanx\Theatron\Template\Slice\WorkspaceViewSlice;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

use function Phalanx\Theatron\Ui\column;
use function Phalanx\Theatron\Ui\input;
use function Phalanx\Theatron\Ui\spinner;
use function Phalanx\Theatron\Ui\text;

class ChatScreen implements Screen, HasStatusBar, HasFocusables, HandlesKeySequences, DeclaresBindings, Mountable
{
    private const array PULSE_COLORS = [242, 245, 248, 251, 254, 251, 248, 245];
    private const int MAX_COMPOSER_ROWS = 5;
    private const int THINKING_PREVIEW_ROWS = 7;

    private(set) Signal $inputText;
    private(set) Signal $inputCursor;
    private(set) Signal $inputKillRing;
    private(set) ChatConversationHandler $conversationHandler;
    private(set) ChatInputHandler $inputHandler;
    private MarkdownRenderer $markdown;
    private ?TaskScope $scope = null;
    private ?Navigator $navigator = null;

    public function __construct(
        private(set) AppStore $store,
        private ?AgentRuntime $runtime = null,
    ) {
        $this->inputText = new Signal('');
        $this->inputCursor = new Signal(0);
        $this->inputKillRing = new Signal('');
        $this->conversationHandler = new ChatConversationHandler($this);
        $this->inputHandler = new ChatInputHandler($this);
        $this->markdown = new MarkdownRenderer();
    }

    public function __invoke(ScreenContext $ctx): Renderable
    {
        $this->navigator = $ctx->navigator;
        $composerRows = self::composerRows((string) $this->inputText->get());

        return column(
            $this->renderConversation($ctx->width, max(1, $ctx->height - self::footerRows($composerRows))),
            self::spacer(),
            $this->renderStatusLine(),
            self::spacer(),
            $this->renderInput($composerRows),
            self::rule($this->ruleWidth($ctx->width)),
            self::spacer(),
        );
    }

    public function onMount(TaskScope $scope): void
    {
        $this->scope = $scope;
    }

    public function onUnmount(): void
    {
        $this->scope = null;
    }

    public function statusBar(): Renderable
    {
        $line = $this->activityBlocksFocused()
            ? self::activityControlLine()
            : $this->controlPanelLine();

        return text($line, TdomStyle::of(size: Size::fixed(1)));
    }

    /** @return list<array{string, Focusable}> */
    public function focusables(): array
    {
        return [
            ['conversation', $this->conversationHandler],
            ['input', $this->inputHandler],
        ];
    }

    /** @return list<Binding> */
    public function bindings(): array
    {
        return [
            Binding::ctrl('p')->label('up'),
            Binding::key(Key::Up)->label('focus'),
            Binding::key(Key::Down)->label('focus'),
            Binding::key(Key::Enter)->label('send'),
        ];
    }

    public function handleScroll(KeyEvent $event): bool
    {
        if ($event->is('j') || $event->is(Key::Down) || ($event->ctrl && $event->is('n'))) {
            $this->store->workspaceView = $this->store->workspaceView->scrollChatDown();

            return true;
        }

        if ($event->is('k') || $event->is(Key::Up) || ($event->ctrl && $event->is('p'))) {
            $this->store->workspaceView = $this->store->workspaceView->scrollChatUp($this->store->conversation);

            return true;
        }

        if ($event->is('G')) {
            $this->store->workspaceView = $this->store->workspaceView->scrollChatToOldest($this->store->conversation);

            return true;
        }

        return false;
    }

    public function startsKeySequence(KeyEvent $event): bool
    {
        return ComposerChordMap::startsSequence($event);
    }

    public function handleKeySequence(KeySequenceState $state, KeyEvent $event): bool
    {
        if (!$state->isAwaitingControlX()) {
            return false;
        }

        if ($event->is(Key::Escape)) {
            return true;
        }

        return match (ComposerChordMap::actionFor($event)) {
            ComposerChordAction::OpenKeymap => $this->openKeymap(),
            ComposerChordAction::OpenDevTools => $this->openDevTools(),
            ComposerChordAction::OpenSettings => $this->openSettings(),
            ComposerChordAction::UndoQueuedInput => $this->undoLastQueuedInput(),
            ComposerChordAction::UndoAllQueuedInput => $this->undoAllQueuedInput(),
            default => false,
        };
    }

    public function submitOrExpand(): bool
    {
        $workspaceView = $this->store->workspaceView;

        if ($workspaceView->chatScrollOffset > 0) {
            $this->store->workspaceView = $workspaceView->expandFocusedChatTurn($this->store->conversation);

            return true;
        }

        return $this->submitInput();
    }

    public function submitInput(): bool
    {
        $text = trim((string) $this->inputText->get());

        if ($text === '') {
            return false;
        }

        $this->setInputText('');
        $this->store->input = $this->store->input->clear();

        if ($this->store->activity->isBusy()) {
            $this->store->input = $this->store->input->enqueue($text);

            return true;
        }

        $this->store->conversation = $this->store->conversation->addUserMessage($text);
        $this->store->workspaceView = $this->store->workspaceView->startChatTurn();
        $this->store->activity = $this->store->activity->withStatus(ActivityStatus::Running);
        $this->runtime?->send($this->scope ?? throw new \RuntimeException('ChatScreen is not mounted.'), $text);

        return true;
    }

    public function undoLastQueuedInput(): bool
    {
        if ((string) $this->inputText->get() !== '') {
            return false;
        }

        $message = $this->store->input->lastQueued();

        if ($message === null) {
            return false;
        }

        $this->setInputText($message);
        $this->store->input = $this->store->input
            ->removeLastQueued()
            ->withText($message);

        return true;
    }

    public function undoAllQueuedInput(): bool
    {
        if ((string) $this->inputText->get() !== '') {
            return false;
        }

        if ($this->store->input->queue === []) {
            return false;
        }

        $text = $this->store->input->queuedText();

        $this->setInputText($text);
        $this->store->input = $this->store->input
            ->clearQueue()
            ->withText($text);

        return true;
    }

    public function syncInputText(): void
    {
        $text = (string) $this->inputText->get();
        $this->inputCursor->set($this->clampCursor((int) $this->inputCursor->get(), $text));
        $this->store->input = $this->store->input->withText($text);
    }

    public function beginInputChordPrefix(): void
    {
        $this->store->keySequence = $this->store->keySequence->beginControlX();
    }

    public function clearInputChordPrefix(): void
    {
        $this->store->keySequence = $this->store->keySequence->clear();
    }

    public function isAwaitingInputChord(): bool
    {
        return $this->store->keySequence->isAwaitingControlX();
    }

    public function openDevTools(): bool
    {
        $this->navigator?->go(DevToolsScreen::class);

        return $this->navigator !== null;
    }

    public function openSettings(): bool
    {
        $this->navigator?->go(SettingsScreen::class);

        return $this->navigator !== null;
    }

    public function openKeymap(): bool
    {
        $this->navigator?->overlay(KeymapOverlay::class);

        return $this->navigator !== null;
    }

    public function openFocusedActivityBlock(): bool
    {
        $workspaceView = $this->store->workspaceView->selectFocusedChatTurn(
            $this->store->conversation,
            returnTarget: self::class,
        );

        if ($workspaceView->selectedTurnId === null || $this->navigator === null) {
            return false;
        }

        $this->store->workspaceView = $workspaceView;
        $this->navigator->go(ConversationBlockDetailScreen::class);

        return true;
    }

    private static function row(Line $line): Renderable
    {
        return text($line, TdomStyle::of(size: Size::fixed(1)));
    }

    private static function spacer(): Renderable
    {
        return self::row(Line::plain(''));
    }

    private static function rule(int $width): Renderable
    {
        return self::row(Line::from(
            Span::styled('  ' . str_repeat('╴', $width), TextStyle::new()->fg(Color::indexed(236))),
        ));
    }

    private static function composerRows(string $text): int
    {
        return min(self::MAX_COMPOSER_ROWS, max(1, count(explode("\n", $text))));
    }

    private static function footerRows(int $composerRows): int
    {
        return $composerRows + 5;
    }

    private static function controlBarMemory(int $bytes): string
    {
        return str_replace(' ', '', Metrics::memory($bytes));
    }

    private static function activityControlLine(): Line
    {
        return Line::from(
            Span::styled('  ↑', TextStyle::new()->fg(Color::indexed(245))),
            Span::styled(' focus', TextStyle::new()->fg(Color::indexed(250))),
            self::pipe(),
            Span::styled('↓', TextStyle::new()->fg(Color::indexed(245))),
            Span::styled(' focus', TextStyle::new()->fg(Color::indexed(250))),
            self::pipe(),
            Span::styled('Enter', TextStyle::new()->fg(Color::indexed(245))),
            Span::styled(' open', TextStyle::new()->fg(Color::indexed(250))),
            self::pipe(),
            Span::styled('i', TextStyle::new()->fg(Color::indexed(245))),
            Span::styled(' compose', TextStyle::new()->fg(Color::indexed(250))),
            self::pipe(),
            Span::styled('^C', TextStyle::new()->fg(Color::indexed(245))),
            Span::styled(' quit', TextStyle::new()->fg(Color::indexed(250))),
        );
    }

    private static function pipe(): Span
    {
        return Span::styled('  │  ', TextStyle::new()->fg(Color::indexed(238)));
    }

    private static function thinkingLabel(int $pulseFrame): string
    {
        return 'thinking' . str_repeat('.', (intdiv($pulseFrame, 3) % 3) + 1);
    }

    /**
     * @return list<Line>
     */
    private static function wrapIndented(string $text, int $maxWidth, string $indent, TextStyle $style): array
    {
        $lineWidth = max(10, $maxWidth - mb_strlen($indent));
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if ($current === '') {
                $current = $word;
            } elseif (mb_strlen($current) + 1 + mb_strlen($word) <= $lineWidth) {
                $current .= ' ' . $word;
            } else {
                $lines[] = Line::from(Span::styled($indent . $current, $style));
                $current = $word;
            }
        }

        if ($current !== '') {
            $lines[] = Line::from(Span::styled($indent . $current, $style));
        }

        return $lines ?: [Line::from(Span::styled($indent, $style))];
    }

    /**
     * @param list<Renderable> $rows
     * @return list<Renderable>
     */
    private static function viewport(array $rows, int $maxRows, bool $stickToBottom): array
    {
        if (count($rows) <= $maxRows) {
            if (!$stickToBottom) {
                return $rows;
            }

            $spacers = [];
            for ($i = 0; $i < $maxRows - count($rows); $i++) {
                $spacers[] = self::spacer();
            }

            return [...$spacers, ...$rows];
        }

        return $stickToBottom
            ? array_slice($rows, -$maxRows)
            : array_slice($rows, 0, $maxRows);
    }

    private function renderConversation(int $width, int $availableHeight): Renderable
    {
        $conversation = $this->store->conversation;
        $workspaceView = $this->store->workspaceView;
        $rows = [];

        $body = $this->renderConversationRows($conversation, $workspaceView, max(20, $width - 2));
        $visible = self::viewport($body, max(1, $availableHeight), $workspaceView->chatScrollOffset === 0);

        return column(...[...$rows, ...$visible])->styled(TdomStyle::of(size: Size::fill()));
    }

    /** @return list<Renderable> */
    private function renderConversationRows(
        ConversationSlice $conversation,
        WorkspaceViewSlice $workspaceView,
        int $wrapWidth,
    ): array {
        if ($conversation->turns === []) {
            return [
                self::row(Line::from(
                    Span::styled('  Type a message to begin.', TextStyle::new()->fg(Color::indexed(242))),
                )),
            ];
        }

        $rows = [];
        $lastTurn = $conversation->turns[array_key_last($conversation->turns)];

        foreach ($conversation->turns as $turn) {
            if ($turn->id === $lastTurn->id || $turn->id === $workspaceView->expandedTurnId) {
                $rows = [...$rows, ...$this->renderExchange($conversation, $workspaceView, $turn, $wrapWidth)];
                continue;
            }

            $rows = [...$rows, ...$this->renderSummary($conversation, $workspaceView, $turn, $wrapWidth)];
        }

        return $rows;
    }

    /** @return list<Renderable> */
    private function renderSummary(
        ConversationSlice $conversation,
        WorkspaceViewSlice $workspaceView,
        ConversationTurn $turn,
        int $wrapWidth,
    ): array {
        $rows = [];
        $focused = $this->activityBlocksFocused() && $workspaceView->focusedTurn($conversation)?->id === $turn->id;

        $userStyle = TextStyle::new()->fg(Color::indexed(250));
        foreach (self::wrapIndented($turn->userText, $wrapWidth, $focused ? '  ▶ ' : '  > ', $userStyle) as $line) {
            $rows[] = self::row($line);
        }

        $summaryRule = '  ' . str_repeat('─', min((int) ($wrapWidth * 0.6), 80));
        $rows[] = self::row(Line::from(
            Span::styled($summaryRule, TextStyle::new()->fg(Color::indexed(238))),
        ));

        if ($turn->hasAssistantText()) {
            $preview = MarkdownRenderer::stripSyntax(mb_substr($turn->assistantText(), 0, 100));
            $previewStyle = TextStyle::new()->fg(Color::indexed(245));
            foreach (self::wrapIndented($preview, $wrapWidth, '    ', $previewStyle) as $line) {
                $rows[] = self::row($line);
            }
        }

        $rows[] = self::row(Line::plain(''));

        return $rows;
    }

    /** @return list<Renderable> */
    private function renderExchange(
        ConversationSlice $conversation,
        WorkspaceViewSlice $workspaceView,
        ConversationTurn $turn,
        int $wrapWidth,
    ): array {
        $focused = $this->activityBlocksFocused() && $workspaceView->focusedTurn($conversation)?->id === $turn->id;
        $rows = [self::row(Line::plain(''))];
        $rows[] = self::row(Line::from(
            Span::styled($focused ? '  ▶ you: ' : '  you: ', TextStyle::new()->fg(Color::indexed(255))->bold()),
            Span::styled($turn->userText, TextStyle::new()->fg(Color::indexed(252))),
        ));
        $exchangeRule = '  ' . str_repeat('─', min(24, (int) ($wrapWidth * 0.2)));
        $rows[] = self::row(Line::from(Span::styled($exchangeRule, TextStyle::new()->fg(Color::indexed(236)))));

        $ephemeralThinking = $this->shouldRenderEphemeralThinking($conversation, $turn);

        if ($ephemeralThinking) {
            $rows = [...$rows, ...$this->renderEphemeralThinking($turn, $wrapWidth)];
        }

        if ($turn->hasAssistantText()) {
            $rows[] = self::row(Line::from(
                Span::styled('  assistant:', TextStyle::new()->fg(Color::indexed(252))->bold()),
            ));
            $rows = [...$rows, ...$this->markdown->render($turn->assistantText(), $wrapWidth, '    ')];
        }

        $rows = [...$rows, ...$this->renderTurnProjections($turn, $wrapWidth)];

        if ($workspaceView->showThinking && !$ephemeralThinking && $turn->hasThinkingText()) {
            $thinkingStyle = TextStyle::new()->fg(Color::indexed(242));
            foreach (self::wrapIndented($turn->thinkingText(), $wrapWidth, '    ', $thinkingStyle) as $line) {
                $rows[] = self::row($line);
            }
        }

        return $rows;
    }

    private function activityBlocksFocused(): bool
    {
        return $this->store->inputMode->focusTarget === 'conversation';
    }

    /**
     * @return list<Renderable>
     */
    private function renderTurnProjections(ConversationTurn $turn, int $wrapWidth): array
    {
        $rows = [];

        foreach (ConversationEventFormatter::threadLines($turn->threadProjectionEvents()) as $line) {
            $lines = self::wrapIndented(
                $line->text,
                $wrapWidth,
                '    ' . ConversationEventRenderPolicy::markerForSeverity($line->severity) . ' ',
                ConversationEventRenderPolicy::style($line->severity),
            );

            foreach ($lines as $line) {
                $rows[] = self::row($line);
            }
        }

        return $rows;
    }

    private function ruleWidth(int $screenWidth): int
    {
        return min(max(1, $screenWidth - 2), max(1, $this->controlPanelLine()->width - 2));
    }

    private function controlPanelLine(): Line
    {
        return Line::from(
            Span::styled('  (Λ)', TextStyle::new()->fg(Color::indexed(245))),
            Span::styled(' ' . $this->store->activity->modelName, TextStyle::new()->fg(Color::indexed(250))),
            Span::styled('  ' . $this->store->runtimeStatus->cwdLabel(), TextStyle::new()->fg(Color::indexed(242))),
            Span::styled('  mem ', TextStyle::new()->fg(Color::indexed(242))),
            Span::styled(self::controlBarMemory(memory_get_usage(false)), TextStyle::new()->fg(Color::indexed(250))),
            Span::styled(' · alloc ', TextStyle::new()->fg(Color::indexed(242))),
            Span::styled(self::controlBarMemory(memory_get_usage(true)), TextStyle::new()->fg(Color::indexed(250))),
            Span::styled('  ' . $this->store->inputMode->mode->value, TextStyle::new()->fg(Color::indexed(250))),
            Span::styled('  ^X ?', TextStyle::new()->fg(Color::indexed(245))),
            Span::styled(' keymap', TextStyle::new()->fg(Color::indexed(250))),
        );
    }

    private function shouldRenderEphemeralThinking(ConversationSlice $conversation, ConversationTurn $turn): bool
    {
        if (!$conversation->isStreaming || !$turn->hasThinkingText()) {
            return false;
        }

        $lastTurn = $conversation->turns[array_key_last($conversation->turns)] ?? null;

        return $lastTurn?->id === $turn->id;
    }

    /**
     * @return list<Renderable>
     */
    private function renderEphemeralThinking(ConversationTurn $turn, int $wrapWidth): array
    {
        $pulseFrame = $this->store->activity->pulseFrame;
        $thinkingStyle = TextStyle::new()->fg(Color::indexed(242))->dim();
        $wrapped = self::wrapIndented($turn->thinkingText(), $wrapWidth, '    ', $thinkingStyle);
        $rows = [
            spinner(
                label: self::thinkingLabel($pulseFrame),
                frame: $pulseFrame,
                style: TdomStyle::of(size: Size::fixed(1), color: Color::indexed(242)),
            ),
        ];

        foreach (array_slice($wrapped, -self::THINKING_PREVIEW_ROWS) as $line) {
            $rows[] = self::row($line);
        }

        return $rows;
    }

    private function renderStatusLine(): Renderable
    {
        $activity = $this->store->activity;
        $input = $this->store->input;
        $status = strtolower($activity->status->name);
        $color = $status === 'idle'
            ? Color::indexed(242)
            : Color::indexed(self::PULSE_COLORS[intdiv($activity->pulseFrame, 3) % count(self::PULSE_COLORS)]);
        $spans = [
            Span::styled('  Λ ', TextStyle::new()->fg($color)),
            Span::styled($status, TextStyle::new()->fg(Color::indexed(242))),
        ];

        if ($input->queue !== []) {
            $count = count($input->queue);
            $spans[] = self::pipe();
            $queuedText = $count === 1 ? '1 queued' : "{$count} queued";
            $spans[] = Span::styled($queuedText, TextStyle::new()->fg(Color::indexed(242)));

            if ((string) $this->inputText->get() === '') {
                $spans[] = self::pipe();
                $spans[] = Span::styled('^X u undo', TextStyle::new()->fg(Color::indexed(245)));

                if ($count > 1) {
                    $spans[] = self::pipe();
                    $spans[] = Span::styled('^X a undo all', TextStyle::new()->fg(Color::indexed(245)));
                }
            }
        }

        if ($this->store->keySequence->isAwaitingControlX()) {
            $spans[] = self::pipe();
            $spans[] = Span::styled('^X …', TextStyle::new()->fg(Color::indexed(245)));
        }

        return self::row(Line::from(...$spans));
    }

    private function renderInput(int $rows): Renderable
    {
        $text = (string) $this->inputText->get();

        if ($rows > 1) {
            return $this->renderMultilineInput($text, $rows);
        }

        return input(
            value: $text,
            prompt: '  +> ',
            cursor: $this->clampCursor((int) $this->inputCursor->get(), $text),
            style: TdomStyle::of(size: Size::fixed(1)),
        );
    }

    private function renderMultilineInput(string $text, int $rows): Renderable
    {
        $lines = explode("\n", $text);
        [$cursorLine, $cursorColumn] = $this->cursorLine((int) $this->inputCursor->get(), $text);
        $firstVisible = max(0, min($cursorLine, count($lines) - $rows));
        $visible = array_slice($lines, $firstVisible, $rows);
        $children = [];

        foreach ($visible as $i => $line) {
            $prompt = $firstVisible + $i === 0 ? '  +> ' : '     ';
            $isCursorLine = $firstVisible + $i === $cursorLine;

            if ($isCursorLine) {
                $children[] = input(
                    value: $line,
                    prompt: $prompt,
                    cursor: $cursorColumn,
                    style: TdomStyle::of(size: Size::fixed(1)),
                );

                continue;
            }

            $children[] = self::row(Line::from(
                Span::styled($prompt . $line, TextStyle::new()->fg(Color::indexed(252))),
            ));
        }

        return column(...$children)->styled(TdomStyle::of(size: Size::fixed($rows)));
    }

    private function clampCursor(int $cursor, string $text): int
    {
        return max(0, min($cursor, mb_strlen($text)));
    }

    /**
     * @return array{int, int}
     */
    private function cursorLine(int $cursor, string $text): array
    {
        $cursor = $this->clampCursor($cursor, $text);
        $before = mb_substr($text, 0, $cursor);
        $lines = explode("\n", $before);
        $line = max(0, count($lines) - 1);
        $column = mb_strlen($lines[array_key_last($lines)] ?? '');

        return [$line, $column];
    }

    private function setInputText(string $text): void
    {
        $this->inputText->set($text);
        $this->inputCursor->set(mb_strlen($text));
        $this->clearInputChordPrefix();
    }
}
