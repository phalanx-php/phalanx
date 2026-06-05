<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Identity;

enum RuntimeResourceSid: string implements RuntimeResourceId
{
    case Scope = 'runtime.scope';
    case StreamingProcess = 'runtime.streaming_process';
    case TaskRun = 'runtime.task_run';
    case Test = 'runtime.test';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
