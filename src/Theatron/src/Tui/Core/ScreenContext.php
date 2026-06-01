<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Tui\Core\MountSystem;
use Phalanx\Theatron\Tui\Drawing\RenderDiagnostics;
use Phalanx\Theatron\Tui\Navigation\Navigator;
use Phalanx\Theatron\Tui\Styles\Theme;

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
