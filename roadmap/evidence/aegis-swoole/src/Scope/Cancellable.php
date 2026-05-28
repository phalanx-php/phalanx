<?php

declare(strict_types=1);

namespace AegisSwoole\Scope;

use AegisSwoole\Cancellation\CancellationToken;

interface Cancellable
{
    public bool $isCancelled { get; }

    public function throwIfCancelled(): void;

    public function cancellation(): CancellationToken;
}
