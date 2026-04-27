<?php

declare(strict_types=1);

namespace Phalanx;

use Phalanx\Concurrency\CancellationToken;

interface Cancellable
{
    public bool $isCancelled { get; }

    public function throwIfCancelled(): void;

    public function cancellation(): CancellationToken;
}
