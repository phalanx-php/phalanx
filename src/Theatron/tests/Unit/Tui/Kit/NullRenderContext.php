<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Tui\Kit;

use Phalanx\Theatron\Tui\Core\MountSystem;
use Phalanx\Theatron\Tui\Core\RenderContext;
use Phalanx\Theatron\Tui\Styles\Theme;
use Phalanx\Theatron\Tests\Support\RecordingTaskScope;

final class NullRenderContext extends RenderContext
{
    public function __construct()
    {
        $scope = new RecordingTaskScope();

        parent::__construct($scope, Theme::default(), new MountSystem($scope));
    }
}
