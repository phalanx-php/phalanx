<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Tui\Styles\Stylesheet;
use Phalanx\Tui\Styles\Theme;

interface Styled
{
    public function stylesheet(Theme $theme): Stylesheet;
}
