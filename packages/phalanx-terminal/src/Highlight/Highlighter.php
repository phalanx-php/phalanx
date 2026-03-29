<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Highlight;

use Phalanx\Terminal\Widget\Text\Line;

interface Highlighter
{
    /** @return list<Line> */
    public function highlight(string $code): array;
}
