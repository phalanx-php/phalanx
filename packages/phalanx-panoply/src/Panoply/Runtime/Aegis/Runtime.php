<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Runtime\Aegis;

use Phalanx\Panoply\Runtime as RuntimeContract;
use Phalanx\Panoply\Runtime\CancellationException;
// Importing Phalanx\Scope and Phalanx\Supervisor here is the documented
// exception to the Phalanx-independence boundary — this file IS the bridge.
use Phalanx\Scope\TaskScope;
use Phalanx\Supervisor\WaitReason;

/**
 * Aegis-backed {@see RuntimeContract}. Wraps a {@see TaskScope} and
 * delegates every Runtime method to the corresponding scope operation.
 *
 * Importing from `Phalanx\Scope` is the documented exception to the
 * otherwise-strict Phalanx-independence boundary: this class IS a
 * first-party adapter that exists to bridge panoply with the Aegis runtime.
 *
 * {@see self::onCancel()} wraps {@see TaskScope::onDispose()} with a
 * cancellation guard so cleanups only run when the scope was actually
 * cancelled, not on normal teardown. This preserves the panoply onCancel()
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
        // Translate panoply's nullable string label into an Aegis typed
        // WaitReason using WaitReason::custom(). The scope still runs under
        // full Aegis supervision (cancellation, diagnostics, tracing)
        // regardless of whether a label is provided.
        $aegisReason = $waitReason !== null ? WaitReason::custom($waitReason) : null;
        // @phpstan-ignore-next-line argument.templateType
        return $this->scope->call($work, $aegisReason);
    }

    public function isCancelled(): bool
    {
        return $this->scope->isCancelled;
    }

    public function throwIfCancelled(): void
    {
        try {
            $this->scope->throwIfCancelled();
        } catch (\Throwable $e) {
            // Re-wrap as panoply's CancellationException for cross-runtime
            // symmetry. Chains the Aegis exception as previous so the full
            // stack is available for diagnostics.
            throw new CancellationException($e->getMessage(), 0, $e);
        }
    }

    public function onCancel(\Closure $cleanup): void
    {
        // Aegis onDispose() fires on BOTH normal teardown and cancellation.
        // Gate to cancellation-only so the panoply onCancel() semantic
        // (cleanup runs only when the scope was actually cancelled) is upheld.
        $scope = $this->scope;
        $this->scope->onDispose(static function () use ($scope, $cleanup): void {
            if ($scope->isCancelled) {
                $cleanup();
            }
        });
    }
}
