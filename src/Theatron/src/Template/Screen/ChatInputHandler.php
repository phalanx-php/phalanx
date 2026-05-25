<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Screen;

use Phalanx\Theatron\Contract\AcceptsInput;
use Phalanx\Theatron\Contract\Focusable;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Kit\TextInputBehavior;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Template\Keymap\ComposerChordAction;
use Phalanx\Theatron\Template\Keymap\ComposerChordMap;

/**
 * Handles typed text input and Enter-to-submit for the chat composer.
 * Returned by ChatScreen::focusables() as the 'input' focusable.
 *
 * Characters are accumulated in ChatScreen::$inputText via TextInputBehavior.
 * Enter submits the accumulated text through ChatScreen::submitInput().
 */
final class ChatInputHandler implements Focusable, AcceptsInput
{
    use TextInputBehavior;

    public function __construct(
        private ChatScreen $screen,
    ) {
    }

    public function handleInput(KeyEvent $event): bool
    {
        if ($this->screen->isAwaitingInputChord()) {
            $this->screen->clearInputChordPrefix();

            if ($event->is(Key::Escape)) {
                return true;
            }

            $this->handleChord($event);

            return true;
        }

        if (ComposerChordMap::startsSequence($event)) {
            $this->screen->beginInputChordPrefix();

            return true;
        }

        if ($event->is(Key::Enter) && !$event->shift) {
            return $this->screen->submitOrExpand();
        }

        if ($event->is(Key::Enter) && $event->shift) {
            $handled = $this->handleTextInput(new KeyEvent("\n"));

            if ($handled) {
                $this->screen->syncInputText();
            }

            return $handled;
        }

        $handled = $this->handleTextInput($event);

        if ($handled) {
            $this->screen->syncInputText();

            return true;
        }

        return false;
    }

    protected function inputSignal(): Signal
    {
        return $this->screen->inputText;
    }

    protected function inputCursorSignal(): Signal
    {
        return $this->screen->inputCursor;
    }

    protected function inputKillRingSignal(): Signal
    {
        return $this->screen->inputKillRing;
    }

    private function handleChord(KeyEvent $event): bool
    {
        return match (ComposerChordMap::actionFor($event)) {
            ComposerChordAction::OpenKeymap => $this->screen->openKeymap(),
            ComposerChordAction::OpenDevTools => $this->screen->openDevTools(),
            ComposerChordAction::OpenSettings => $this->screen->openSettings(),
            ComposerChordAction::UndoQueuedInput => $this->screen->undoLastQueuedInput(),
            ComposerChordAction::UndoAllQueuedInput => $this->screen->undoAllQueuedInput(),
            default => false,
        };
    }
}
