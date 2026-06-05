<?php

declare(strict_types=1);

namespace Phalanx\Tui\Inputs;

use Closure;
use Phalanx\Tui\Core\AcceptsInput;
use Phalanx\Tui\Inputs\FocusManager;

class ModeDispatcher
{
    private(set) InputMode $mode = InputMode::Normal;

    private ?string $focusTarget = null;

    /** @var ?Closure(InputMode, ?string): void */
    private ?Closure $onModeChange = null;

    public function __construct(
        private(set) FocusManager $focus,
    ) {
    }

    /** @param Closure(InputMode, ?string): void $callback */
    public function onModeChange(Closure $callback): void
    {
        $this->onModeChange = $callback;
    }

    public function dispatch(InputEvent $event): bool
    {
        if (!$event instanceof KeyEvent) {
            return false;
        }

        if ($this->mode === InputMode::Insert) {
            return $this->dispatchInsert($event);
        }

        return $this->dispatchNormal($event);
    }

    public function syncModeWithActiveFocus(): void
    {
        $this->autoModeForActive();
    }

    public function restore(InputMode $mode, ?string $focusTarget): void
    {
        if ($focusTarget === null || !in_array($focusTarget, $this->focus->names(), true)) {
            $this->autoModeForActive();

            return;
        }

        $this->focus->focus($focusTarget);
        $this->setMode($mode);
    }

    private function dispatchNormal(KeyEvent $event): bool
    {
        if ($event->is(Key::Tab)) {
            if ($event->shift) {
                $this->focus->previous();
            } else {
                $this->focus->next();
            }

            $this->autoModeForActive();

            return true;
        }

        $active = $this->focus->active();

        if ($active instanceof NormalModeHandler && $active->handleNormalKey($event)) {
            return true;
        }

        if ($event->is('i') && in_array('input', $this->focus->names(), true)) {
            $this->focus->focus('input');
            $this->setMode(InputMode::Insert);

            return true;
        }

        if ($event->is('h') || $event->is(Key::Left)) {
            $this->focus->previous();
            $this->autoModeForActive();

            return true;
        }

        if ($event->is('l') || $event->is(Key::Right)) {
            $this->focus->next();
            $this->autoModeForActive();

            return true;
        }

        if ($event->is('i') || $event->is(Key::Enter)) {
            $active = $this->focus->active();

            if ($active instanceof AcceptsInput) {
                $this->setMode(InputMode::Insert);

                return true;
            }

            return false;
        }

        return false;
    }

    private function dispatchInsert(KeyEvent $event): bool
    {
        if ($event->ctrl && $event->is('p') && in_array('conversation', $this->focus->names(), true)) {
            $this->focus->focus('conversation');
            $this->setMode(InputMode::Normal);

            return true;
        }

        if ($event->is(Key::Tab)) {
            if ($event->shift) {
                $this->focus->previous();
            } else {
                $this->focus->next();
            }

            $this->autoModeForActive();

            return true;
        }

        $active = $this->focus->active();

        if ($active instanceof AcceptsInput && $active->handleInput($event)) {
            return true;
        }

        if ($event->is(Key::Escape)) {
            $this->setMode(InputMode::Normal);

            return true;
        }

        return false;
    }

    private function setMode(InputMode $mode): void
    {
        $focusTarget = $this->focus->activeName();

        if ($this->mode === $mode && $this->focusTarget === $focusTarget) {
            return;
        }

        $this->mode = $mode;
        $this->focusTarget = $focusTarget;

        if ($this->onModeChange !== null) {
            ($this->onModeChange)($mode, $focusTarget);
        }
    }

    private function autoModeForActive(): void
    {
        $active = $this->focus->active();

        if ($active instanceof AcceptsInput) {
            $this->setMode(InputMode::Insert);
        } else {
            $this->setMode(InputMode::Normal);
        }
    }
}
