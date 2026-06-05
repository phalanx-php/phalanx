<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Core;

use Phalanx\Tui\Tui\Navigation\Navigator;

interface PreparesWorkspaceDraw
{
    public function prepareWorkspaceDraw(Navigator $navigator): void;
}
