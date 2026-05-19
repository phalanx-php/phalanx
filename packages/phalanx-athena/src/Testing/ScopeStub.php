<?php

declare(strict_types=1);

namespace Phalanx\Athena\Testing;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\TaskScope;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;

/**
 * Minimal TaskScope implementation for tests and demos that need a scope
 * without a live Aegis runtime. Supports cancellation and disposal; throws
 * on service resolution and trace access.
 */
final class ScopeStub implements TaskScope
{
    public bool $isCancelled { get => $this->token->isCancelled; }

    public RuntimeContext $runtime {
        get => throw new \RuntimeException('Runtime is not implemented by ScopeStub.');
    }

    private CancellationToken $token;

    /** @var list<\Closure(): void> */
    private array $disposeStack = [];

    public function __construct(?CancellationToken $token = null)
    {
        $this->token = $token ?? CancellationToken::create();
    }

    public function call(\Closure $fn, ?WaitReason $waitReason = null): mixed
    {
        if (!new \ReflectionFunction($fn)->isStatic()) {
            throw new \LogicException(
                self::class . '::call() requires a static closure (matches ExecutionLifecycleScope contract).',
            );
        }

        return $fn();
    }

    public function throwIfCancelled(): void
    {
        $this->token->throwIfCancelled();
    }

    public function cancellation(): CancellationToken
    {
        return $this->token;
    }

    public function onDispose(\Closure $callback): void
    {
        $this->disposeStack[] = $callback;
    }

    public function dispose(): void
    {
        $callbacks = array_reverse($this->disposeStack);
        $this->disposeStack = [];

        foreach ($callbacks as $callback) {
            $callback();
        }
    }

    public function service(string $type): object
    {
        throw new \RuntimeException('Service lookup is not implemented by ScopeStub.');
    }

    public function trace(): Trace
    {
        throw new \RuntimeException('Trace is not implemented by ScopeStub.');
    }

    public function execute(Scopeable|Executable|\Closure $task): mixed
    {
        return $task instanceof \Closure ? $task() : throw new \RuntimeException('Task execution is not implemented.');
    }

    public function executeFresh(Scopeable|Executable|\Closure $task): mixed
    {
        return $this->execute($task);
    }
}
