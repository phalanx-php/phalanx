<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Scope\Scope;
use Phalanx\Tui\Core\MountSystem;
use Phalanx\Tui\Drawing\RenderDiagnostics;
use Phalanx\Tui\Inputs\BindingHintsFormatter;
use Phalanx\Tui\Inputs\BindingRegistry;
use Phalanx\Tui\Styles\Theme;
use Phalanx\Tui\Tdom\Renderable;

use function Phalanx\Tui\Kit\row;

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
