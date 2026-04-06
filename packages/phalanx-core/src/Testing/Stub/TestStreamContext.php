<?php

declare(strict_types=1);

namespace Phalanx\Testing\Stub;

use Closure;
use Phalanx\Stream\Contract\StreamContext;
use React\Promise\PromiseInterface;
use RuntimeException;

use function React\Async\await;

final class TestStreamContext implements StreamContext
{
    /** @var list<Closure> */
    private array $disposeCallbacks = [];

    private bool $cancelled = false;

    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw new RuntimeException('Cancelled');
        }
    }

    public function onDispose(Closure $callback): void
    {
        $this->disposeCallbacks[] = $callback;
    }

    /**
     * @param PromiseInterface<mixed> $promise
     */
    public function await(PromiseInterface $promise): mixed
    {
        return await($promise);
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function dispose(): void
    {
        foreach ($this->disposeCallbacks as $cb) {
            $cb();
        }
        $this->disposeCallbacks = [];
    }

}
