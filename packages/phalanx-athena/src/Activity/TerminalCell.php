<?php

declare(strict_types=1);

namespace Phalanx\Athena\Activity;

final class TerminalCell
{
    private(set) ?TerminalState $value = null;

    public function resolve(TerminalState $state): void
    {
        $this->value = $state;
    }
}
