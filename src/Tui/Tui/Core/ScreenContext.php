<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Core;

use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Tui\Core\MountSystem;
use Phalanx\Tui\Tui\Drawing\RenderDiagnostics;
use Phalanx\Tui\Tui\Navigation\Navigator;
use Phalanx\Tui\Tui\Styles\Theme;

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
