<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Component;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\InputTarget;
use Phalanx\Theatron\Tdom\Renderable;

final class MountedPureComponent implements InputTarget
{
    private(set) bool $isDirty = true;

    private PureContext $ctx;
    private ?Renderable $cached = null;
    private int $lastRenderedId = -1;

    public function __construct(
        private PureComponent $component,
        private readonly ?TaskScope $scope = null,
    ) {
        $this->ctx = new PureContext($this->scope);
    }

    public function render(): Renderable
    {
        $currentId = spl_object_id($this->component);

        if ($currentId === $this->lastRenderedId && $this->cached !== null) {
            return $this->cached;
        }

        $this->cached = ($this->component)($this->ctx);
        $this->lastRenderedId = $currentId;

        return $this->cached;
    }

    public function update(PureComponent $component): void
    {
        if (spl_object_id($component) === spl_object_id($this->component)) {
            return;
        }

        $this->ctx->dispose();
        $this->component = $component;
        $this->ctx = new PureContext($this->scope);
        $this->cached = null;
        $this->lastRenderedId = -1;
        $this->isDirty = true;
    }

    public function consumeDirty(): bool
    {
        $wasDirty = $this->isDirty;
        $this->isDirty = false;

        return $wasDirty;
    }

    public function dispose(): void
    {
        $this->ctx->dispose();
        $this->cached = null;
    }

    public function handleInput(InputEvent $event): bool
    {
        if ($this->component instanceof InputTarget) {
            return $this->component->handleInput($event);
        }

        return false;
    }
}
