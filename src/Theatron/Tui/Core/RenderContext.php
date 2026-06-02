<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Scope\Scope;
use Phalanx\Theatron\Tui\Core\MountSystem;
use Phalanx\Theatron\Tui\Drawing\RenderDiagnostics;
use Phalanx\Theatron\Tui\Inputs\BindingHintsFormatter;
use Phalanx\Theatron\Tui\Inputs\BindingRegistry;
use Phalanx\Theatron\Tui\Styles\Theme;
use Phalanx\Theatron\Tui\Tdom\Renderable;

use function Phalanx\Theatron\Tui\Kit\row;

class RenderContext
{
    protected(set) RenderDiagnostics $renderDiagnostics;

    public function __construct(
        protected(set) Scope $scope,
        protected(set) Theme $theme,
        protected(set) MountSystem $mountSystem,
        protected ?BindingRegistry $bindings = null,
        ?RenderDiagnostics $renderDiagnostics = null,
    ) {
        $this->renderDiagnostics = $renderDiagnostics ?? new RenderDiagnostics();
    }

    public function hints(): Renderable
    {
        if ($this->bindings === null) {
            return row();
        }

        return BindingHintsFormatter::render($this->bindings->activeBindings());
    }
}
