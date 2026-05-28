<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Component\StatefulContext;
use Phalanx\Theatron\Tdom\Renderable;

final class DevToolsOverlay implements StatefulComponent
{
    private(set) DevToolsTabView $tabView;

    public function __construct()
    {
        $this->tabView = new DevToolsTabView();
    }

    public function __invoke(StatefulContext $ctx): Renderable
    {
        return ($this->tabView)($ctx);
    }
}
