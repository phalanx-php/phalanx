<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Scope;

use OpenSwoole\Coroutine\Channel;
use Phalanx\Swoole\Mvp\Runtime\Future;

interface Suspendable
{
    public function awaitChannel(Channel $channel): mixed;

    public function awaitFuture(Future $future): mixed;
}
