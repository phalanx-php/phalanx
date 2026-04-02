<?php

declare(strict_types=1);

namespace Phalanx\Stream\Contract;

use Closure;
use React\Promise\PromiseInterface;

interface StreamContext
{
    public function throwIfCancelled(): void;

    public function onDispose(Closure $callback): void;

    public function await(PromiseInterface $promise): mixed;
}
