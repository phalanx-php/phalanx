<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Inputs;

use Closure;
use InvalidArgumentException;
use Phalanx\Theatron\Tui\Inputs\Key;
use Phalanx\Theatron\Tui\Inputs\KeyEvent;
use ReflectionFunction;

final class Binding
{
    private(set) ?BindingAction $action = null;
    private(set) ?string $label = null;

    private function __construct(
        private(set) Key|string $key,
        private(set) bool $ctrl,
        private(set) bool $alt,
        private(set) bool $shift,
    ) {
    }

    public static function ctrl(string $key): self
    {
        return new self($key, ctrl: true, alt: false, shift: false);
    }

    public static function alt(string $key): self
    {
        return new self($key, ctrl: false, alt: true, shift: false);
    }

    public static function key(Key|string $key): self
    {
        return new self($key, ctrl: false, alt: false, shift: false);
    }

    public function quit(): self
    {
        return $this->withAction(BindingAction::quit());
    }

    /**
     * @param class-string $screen
     */
    public function workspace(string $screen): self
    {
        return $this->withAction(BindingAction::workspace($screen));
    }

    /**
     * @param class-string $component
     */
    public function toggle(string $component): self
    {
        return $this->withAction(BindingAction::toggle($component));
    }

    public function back(): self
    {
        return $this->withAction(BindingAction::back());
    }

    public function action(Closure $callback): self
    {
        $rf = new ReflectionFunction($callback);

        if (!$rf->isStatic()) {
            throw new InvalidArgumentException(
                'Binding action closures must be declared static to prevent object capture.',
            );
        }

        return $this->withAction(BindingAction::action($callback));
    }

    public function label(string $label): self
    {
        $clone = clone $this;
        $clone->label = $label;

        return $clone;
    }

    public function matches(KeyEvent $event): bool
    {
        if ($event->key !== $this->key) {
            return false;
        }

        if ($event->ctrl !== $this->ctrl) {
            return false;
        }

        if ($event->alt !== $this->alt) {
            return false;
        }

        if ($event->shift !== $this->shift) {
            return false;
        }

        return true;
    }

    private function withAction(BindingAction $action): self
    {
        $clone = clone $this;
        $clone->action = $action;

        return $clone;
    }
}
