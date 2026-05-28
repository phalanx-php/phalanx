<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Component;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\InputTarget;
use Phalanx\Theatron\Reactive\DirtyBatch;
use Phalanx\Theatron\Store\Lens;
use Phalanx\Theatron\Tdom\Renderable;

final class MountedComponent implements InputTarget
{
    public int $renderRequests {
        get => $this->dirty->requests;
    }

    public bool $isDirty {
        get => $this->dirty->isDirty;
    }

    private(set) StatefulContext $state;

    private readonly DirtyBatch $dirty;

    public function __construct(
        private readonly StatefulComponent $component,
        ?ExecutionScope $scope = null,
        ?Lens $lens = null,
    ) {
        $this->dirty = new DirtyBatch();
        $this->state = new StatefulContext($this->dirty, $scope, $lens);
    }

    public function componentClass(): string
    {
        return $this->component::class;
    }

    public function render(): Renderable
    {
        $this->state->beginRender();

        return ($this->component)($this->state);
    }

    public function consumeDirty(): bool
    {
        return $this->dirty->consume();
    }

    public function handleInput(InputEvent $event): bool
    {
        if (!$this->component instanceof InputTarget) {
            return false;
        }

        return $this->component->handleInput($event);
    }

    public function dispose(): void
    {
        $this->state->dispose();
    }
}
