<?php

declare(strict_types=1);

namespace Phalanx\System;

use RuntimeException;

/**
 * Raised when SystemCommand cannot start the process or when CommandResult
 * is asked to throw on a non-zero exit. Wraps the underlying failure detail
 * as the message so call-site try/catch ergonomics stay obvious.
 */
final class SystemCommandException extends RuntimeException
{
}
