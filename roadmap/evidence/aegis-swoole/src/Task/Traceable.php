<?php

declare(strict_types=1);

namespace AegisSwoole\Task;

/**
 * Behavioral interface declaring the task wants Trace events emitted around
 * its execution. The middleware emits TraceType::Execute on entry and exit.
 */
interface Traceable
{
    public function traceName(): string;
}
