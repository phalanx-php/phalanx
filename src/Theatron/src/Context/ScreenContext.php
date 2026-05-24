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
    private(set) RenderDiagnostics $renderDiagnostics;

    public function __construct(
        private(set) TaskScope $scope,
        private(set) Theme $theme,
        private(set) Navigator $navigator,
        private(set) MountSystem $mountSystem,
        ?RenderDiagnostics $renderDiagnostics = null,
        private(set) int $width = 120,
        private(set) int $height = 24,
    ) {
        $this->renderDiagnostics = $renderDiagnostics ?? new RenderDiagnostics();
    }
}
