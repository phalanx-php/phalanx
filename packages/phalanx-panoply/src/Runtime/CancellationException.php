<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Runtime;

/**
 * Raised when a cancellation signal reaches an in-flight Provider/Transport call.
 *
 * Callers invoke `Runtime::cancel()`; the next `Runtime::throwIfCancelled()` check
 * inside the streaming generator detects the signal and throws this exception.
 * Transport adapters catch this and abort the underlying stream cleanly.
 *
 * Runtime adapters MAY extend this class to carry runtime-specific cancellation
 * reasons — for example, the Aegis runtime adapter wraps Aegis's `Cancelled` token
 * to preserve the cancellation context across the panoply boundary. This is why the
 * class is not `final`: it is an intentional extension point for runtime bridges.
 *
 * Panoply core code relies only on the type ({@see self::class}) for catch clauses
 * and re-throw guards — it never inspects runtime-specific subclass fields.
 *
 * Thrown by {@see \Phalanx\Panoply\Runtime\Sync\Runtime::throwIfCancelled()}.
 */
class CancellationException extends \RuntimeException
{
}
