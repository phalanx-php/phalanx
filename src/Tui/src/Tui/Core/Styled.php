<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Core;

use Phalanx\Tui\Tui\Styles\Stylesheet;
use Phalanx\Tui\Tui\Styles\Theme;

interface Styled
{
    public function stylesheet(Theme $theme): Stylesheet;
}
