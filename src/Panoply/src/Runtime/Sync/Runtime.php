<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Runtime\Sync;

use Phalanx\Panoply\Runtime as RuntimeContract;
use Phalanx\Panoply\Runtime\CancellationException;

/**
 * Synchronous {@see RuntimeContract} for tests and the Fake transport.
 * No coroutines, no Aegis dependency. Cancellation is a settable flag;
 * cleanup closures registered via {@see self::onCancel()} run LIFO when
 * {@see self::cancel()} fires.
 *
 * Eager-cleanup invariant: cleanups registered after {@see self::cancel()}
 * has already fired are invoked immediately rather than queued. This
 * prevents a silent dead-queue footgun where late registrations are
 * silently dropped.
 *
 * Final — extension would alter cancellation semantics that consumers
 * depend on.
 */
final class Runtime implements RuntimeContract
{
    private bool $cancelled = false;

    /** @var list<\Closure> */
    private array $cleanups = [];

    public function call(\Closure $work, ?string $waitReason = null): mixed
    {
        $this->throwIfCancelled();

        return $work();
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw new CancellationException('Sync runtime cancelled');
        }
    }

    public function onCancel(\Closure $cleanup): void
    {
        if ($this->cancelled) {
            $cleanup();

            return;
        }

        $this->cleanups[] = $cleanup;
    }

    /**
     * Cancel the runtime. Sets the cancelled flag and runs registered
     * cleanups in LIFO order. Idempotent — calling twice has no effect.
     *
     * @internal Public for host/test control only; not part of the
     *           {@see RuntimeContract} interface. Subclasses or wrapping
     *           runtimes should treat this as an out-of-band cancellation
     *           channel, not a contract method.
     */
    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;

        while (($cleanup = array_pop($this->cleanups)) !== null) {
            $cleanup();
        }
    }
}
