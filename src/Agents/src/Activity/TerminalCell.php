<?php

declare(strict_types=1);

namespace Phalanx\Agents\Activity;

final class TerminalCell
{
    private(set) ?TerminalState $value = null;

    public function resolve(TerminalState $state): void
    {
        if ($this->value !== null) {
            throw new \LogicException('TerminalCell has already been resolved.');
        }

        $this->value = $state;
    }
}
