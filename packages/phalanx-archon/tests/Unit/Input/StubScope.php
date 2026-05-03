<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Input;

use Closure;
use Phalanx\Scope\Disposable;
use Phalanx\Scope\Suspendable;
use Phalanx\Supervisor\WaitReason;

/**
 * Test scope satisfying Suspendable&Disposable. call() invokes the closure
 * synchronously so prompt loops complete in-process; onDispose() collects
 * callbacks for assertion. dispose() runs them in LIFO order.
 */
final class StubScope implements Suspendable, Disposable
{
    /** @var list<Closure(): void> */
    public array $disposeCallbacks = [];

    /** @var list<?WaitReason> */
    public array $callReasons = [];

    public function call(Closure $fn, ?WaitReason $waitReason = null): mixed
    {
        $this->callReasons[] = $waitReason;

        return $fn();
    }

    public function onDispose(Closure $callback): void
    {
        $this->disposeCallbacks[] = $callback;
    }

    public function dispose(): void
    {
        while (($cb = array_pop($this->disposeCallbacks)) !== null) {
            $cb();
        }
    }
}
