<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Kit;

use Phalanx\Tui\Tui\Core\MountSystem;
use Phalanx\Tui\Tui\Core\RenderContext;
use Phalanx\Tui\Tui\Styles\Theme;
use Phalanx\Tui\Tests\Support\RecordingTaskScope;

final class NullRenderContext extends RenderContext
{
    public function __construct()
    {
        $scope = new RecordingTaskScope();

        parent::__construct($scope, Theme::default(), new MountSystem($scope));
    }
}
