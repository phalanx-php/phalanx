<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Input;

use RuntimeException;

/**
 * Thrown by BasePrompt::prompt() when the user cancels (Ctrl+C, EOF).
 * Distinct from Phalanx\Cancellation\Cancelled — that signals scope-level
 * cancellation propagating from outside, while this signals an explicit
 * user-side abort handled by the prompt loop.
 */
final class CancelledException extends RuntimeException
{
}
