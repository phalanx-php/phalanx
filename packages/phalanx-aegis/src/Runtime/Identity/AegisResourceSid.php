<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Identity;

enum AegisResourceSid: string implements RuntimeResourceId
{
    case Scope = 'aegis.scope';
    case TaskRun = 'aegis.task_run';
    case Test = 'aegis.test';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
