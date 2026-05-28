<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Task\Executable;

/**
 * Worker-shippable task. Returns its constructor message + the worker PID so
 * scenarios can assert "ran in a different process".
 */
class SerializableEchoTask implements Executable
{
    public function __construct(public readonly string $message)
    {
    }

    /** @return array{message: string, pid: int} */
    public function __invoke(ExecutionScope $scope): array
    {
        return ['message' => $this->message, 'pid' => (int) getmypid()];
    }
}
