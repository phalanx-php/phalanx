<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Theatron\Styling\Stylesheet;
use Phalanx\Theatron\Styling\Theme;

interface Styled
{
    public function stylesheet(Theme $theme): Stylesheet;
}
