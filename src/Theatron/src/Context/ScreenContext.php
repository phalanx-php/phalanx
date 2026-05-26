<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Context;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Rendering\RenderDiagnostics;
use Phalanx\Theatron\Styling\Theme;

class ScreenContext
{
    protected(set) RenderDiagnostics $renderDiagnostics;

    public function __construct(
        protected(set) TaskScope $scope,
        protected(set) Theme $theme,
        protected(set) Navigator $navigator,
        protected(set) MountSystem $mountSystem,
        ?RenderDiagnostics $renderDiagnostics = null,
        protected(set) int $width = 120,
        protected(set) int $height = 24,
    ) {
        $this->renderDiagnostics = $renderDiagnostics ?? new RenderDiagnostics();
    }
}
