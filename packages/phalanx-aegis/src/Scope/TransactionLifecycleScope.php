<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Closure;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Supervisor\TransactionLease;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Trace\Trace;
use RuntimeException;

final class TransactionLifecycleScope implements TransactionScope
{
    public bool $isCancelled {
        get => $this->scope->isCancelled;
    }

    public RuntimeContext $runtime {
        get => $this->scope->runtime;
    }

    public function __construct(
        private readonly ExecutionLifecycleScope $scope,
        private readonly TransactionLease $lease,
    ) {
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public function service(string $type): object
    {
        return $this->scope->service($type);
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->scope->attribute($key, $default);
    }

    public function withAttribute(string $key, mixed $value): TransactionScope
    {
        $scope = $this->scope->withAttribute($key, $value);
        if (!$scope instanceof ExecutionLifecycleScope) {
            throw new RuntimeException('transaction scope attribute derivation returned an unsupported scope');
        }

        return new self($scope, $this->lease);
    }

    public function trace(): Trace
    {
        return $this->scope->trace();
    }

    public function call(Closure $fn, ?WaitReason $waitReason = null): mixed
    {
        return $this->scope->call($fn, $waitReason);
    }

    public function throwIfCancelled(): void
    {
        $this->scope->throwIfCancelled();
    }

    public function cancellation(): CancellationToken
    {
        return $this->scope->cancellation();
    }

    public function onDispose(Closure $callback): void
    {
        $this->scope->onDispose($callback);
    }

    public function dispose(): void
    {
        // TransactionScope is a narrowed view over the owning execution scope.
        // Disposing it must not dispose the parent request/task scope.
    }

    public function delay(float $seconds): void
    {
        $this->scope->delay($seconds);
    }

    public function transactionLease(): TransactionLease
    {
        return $this->lease;
    }
}
