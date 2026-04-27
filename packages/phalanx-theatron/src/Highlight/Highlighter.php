<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Highlight;

use Phalanx\Theatron\Widget\Text\Line;

interface Highlighter
{
    /** @return list<Line> */
    public function highlight(string $code): array;
}
