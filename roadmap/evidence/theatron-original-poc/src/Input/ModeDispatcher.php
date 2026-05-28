<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

use Closure;
use Phalanx\Theatron\Focus\FocusManager;
use Phalanx\Theatron\Store\StoreWriter;

final class ModeDispatcher
{
    private(set) InputMode $mode = InputMode::Normal;

    /** @var ?Closure(InputMode, ?string): void */
    private ?Closure $onModeChange = null;

    public function __construct(
        private readonly FocusManager $focus,
        private readonly ?StoreWriter $writer = null,
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

            if ($active instanceof InputTarget) {
                $this->setMode(InputMode::Insert);

                return true;
            }

            return false;
        }

        if ($event->is('j') || $event->is('k') || $event->is(Key::Up) || $event->is(Key::Down)) {
            $active = $this->focus->active();

            if ($active instanceof NormalModeHandler) {
                return $active->handleNormalKey($event);
            }

            return false;
        }

        $active = $this->focus->active();

        if ($active instanceof NormalModeHandler) {
            return $active->handleNormalKey($event);
        }

        return false;
    }

    private function dispatchInsert(KeyEvent $event): bool
    {
        if ($event->is(Key::Escape)) {
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

        if ($active instanceof InputTarget) {
            return $active->handleInput($event);
        }

        return false;
    }

    private function setMode(InputMode $mode): void
    {
        $this->mode = $mode;
        $this->syncSlice();

        if ($this->onModeChange !== null) {
            ($this->onModeChange)($mode, $this->focus->activeName());
        }
    }

    private function autoModeForActive(): void
    {
        $active = $this->focus->active();

        if ($active instanceof InputTarget) {
            $this->setMode(InputMode::Insert);
        } else {
            $this->setMode(InputMode::Normal);
        }
    }

    private function syncSlice(): void
    {
        if ($this->writer === null) {
            return;
        }

        $mode = $this->mode;
        $target = $this->focus->activeName();

        $this->writer->set(new InputModeSlice($mode, $target));
    }
}
