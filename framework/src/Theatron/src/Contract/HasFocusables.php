<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

interface HasFocusables
{
    /** @return list<array{string, Focusable}> */
    public function focusables(): array;
}
