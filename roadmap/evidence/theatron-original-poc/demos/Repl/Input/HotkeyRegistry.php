<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Input;

use Phalanx\Theatron\Input\KeyEvent;

final class HotkeyRegistry
{
    /** @var list<HotkeyBinding> */
    private array $bindings = [];

    public function bind(HotkeyBinding $binding): void
    {
        $this->bindings[] = $binding;
    }

    public function dispatch(KeyEvent $event, HotkeyContext $ctx): bool
    {
        foreach ($this->bindings as $binding) {
            if ($binding->matches($event)) {
                ($binding->handler)($ctx);

                return true;
            }
        }

        return false;
    }

    /** @return list<HotkeyBinding> */
    public function bindings(): array
    {
        return $this->bindings;
    }
}
