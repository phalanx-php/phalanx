<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Context;

use Phalanx\Scope\Scope;
use Phalanx\Theatron\Binding\BindingHintsFormatter;
use Phalanx\Theatron\Binding\BindingRegistry;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Rendering\RenderDiagnostics;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Renderable;
use function Phalanx\Theatron\Ui\row;

class RenderContext
{
    private(set) RenderDiagnostics $renderDiagnostics;

    public function __construct(
        private(set) Scope $scope,
        private(set) Theme $theme,
        private(set) MountSystem $mountSystem,
        private ?BindingRegistry $bindings = null,
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
