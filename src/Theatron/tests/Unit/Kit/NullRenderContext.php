<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Kit;

use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tests\Support\RecordingTaskScope;

final class NullRenderContext extends RenderContext
{
    public function __construct()
    {
        $scope = new RecordingTaskScope();

        parent::__construct($scope, Theme::default(), new MountSystem($scope));
    }
}
