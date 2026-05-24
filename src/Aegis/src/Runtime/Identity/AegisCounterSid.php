<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Identity;

enum AegisCounterSid: string implements RuntimeCounterId
{
    case RuntimeEventsDropped = 'aegis.runtime.events.dropped';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
