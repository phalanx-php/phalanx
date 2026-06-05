<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Core;

interface HasFocusables
{
    /** @return list<array{string, Focusable}> */
    public function focusables(): array;
}
