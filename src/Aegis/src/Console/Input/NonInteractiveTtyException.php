<?php

declare(strict_types=1);

namespace Phalanx\Console\Input;

use RuntimeException;

/**
 * Thrown when ConsoleInput is asked for an interactive read on a stream
 * that is not a TTY. Callers that have a degraded fallback (e.g. read a
 * line from stdin then exit) should catch this; callers that genuinely
 * require a terminal should let it propagate.
 */
final class NonInteractiveTtyException extends RuntimeException
{
}
