<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Theatron\Tui\Navigation\Navigator;

interface PreparesWorkspaceDraw
{
    public function prepareWorkspaceDraw(Navigator $navigator): void;
}
