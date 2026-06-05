<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Runtime\Runtime;

use Phalanx\AiProviders\Runtime as RuntimeContract;
use Phalanx\AiProviders\Runtime\CancellationException;
use Phalanx\Cancellation\Cancelled;
// Importing Phalanx\Scope and Phalanx\Supervisor here is the documented
// exception to the Phalanx-independence boundary — this file IS the bridge.
use Phalanx\Scope\TaskScope;
use Phalanx\Supervisor\WaitReason;

/**
 * Runtime-backed {@see RuntimeContract}. Wraps a {@see TaskScope} and
 * delegates every Runtime method to the corresponding scope operation.
 *
 * Importing from `Phalanx\Scope` is the documented exception to the
 * otherwise-strict Phalanx-independence boundary: this class IS a
 * first-party adapter that exists to bridge ai-providers with the Runtime runtime.
 *
 * {@see self::onCancel()} wraps {@see TaskScope::onDispose()} with a
 * cancellation guard so cleanups only run when the scope was actually
 * cancelled, not on normal teardown. This preserves the ai-providers onCancel()
 * semantic across runtimes.
 *
 * Final — sealed delegation contract.
 */
final class Runtime implements RuntimeContract
{
    public function __construct(
        private(set) TaskScope $scope,
    ) {
    }

    public function call(\Closure $work, ?string $waitReason = null): mixed
    {
        // Translate ai-providers's optional label into an Runtime WaitReason.
        $runtimeReason = $waitReason !== null ? WaitReason::custom($waitReason) : null;

        // @phpstan-ignore-next-line argument.templateType
        return $this->scope->call($work, $runtimeReason);
    }

    public function isCancelled(): bool
    {
        return $this->scope->isCancelled;
    }

    public function throwIfCancelled(): void
    {
        try {
            $this->scope->throwIfCancelled();
        } catch (Cancelled $e) {
            throw new CancellationException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            // Keep ai-providers's cancellation type while preserving diagnostics.
            throw new CancellationException($e->getMessage(), 0, $e);
        }
    }

    public function onCancel(\Closure $cleanup): void
    {
        // Gate Runtime disposal to ai-providers's cancellation-only semantic.
        // WeakReference prevents the dispose closure from retaining its own scope.
        $weakScope = \WeakReference::create($this->scope);
        $this->scope->onDispose(static function () use ($weakScope, $cleanup): void {
            $scope = $weakScope->get();
            if ($scope !== null && $scope->isCancelled) {
                $cleanup();
            }
        });
    }
}
