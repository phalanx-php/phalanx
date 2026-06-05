<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Identity;

enum RuntimeCounterSid: string implements RuntimeCounterId
{
    case RuntimeEventsDropped = 'runtime.runtime.events.dropped';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
