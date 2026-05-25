<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Theatron\Navigation\Navigator;

interface PreparesWorkspaceDraw
{
    public function prepareWorkspaceDraw(Navigator $navigator): void;
}
