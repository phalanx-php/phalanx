<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Theatron\Tui\Styles\Stylesheet;
use Phalanx\Theatron\Tui\Styles\Theme;

interface Styled
{
    public function stylesheet(Theme $theme): Stylesheet;
}
