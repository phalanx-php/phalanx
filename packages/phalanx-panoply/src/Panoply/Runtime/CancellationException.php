<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Runtime;

/**
 * Thrown by {@see \Phalanx\Panoply\Runtime\Sync\Runtime::throwIfCancelled()} when
 * the runtime scope has been cancelled. Runtime adapters (e.g. the Aegis adapter)
 * may extend this class to carry runtime-specific cancellation reasons; the
 * panoply core only relies on the type for catch clauses and re-throw guards.
 */
class CancellationException extends \RuntimeException
{
}
