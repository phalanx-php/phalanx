<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\State;

final class EffectSlice
{
    /** @var list<string> */
    private(set) array $pending;

    /**
     * @param list<string> $pending
     */
    public function __construct(array $pending = [])
    {
        $this->pending = array_values($pending);
    }
}
