<?php

declare(strict_types=1);

namespace Phalanx\Err;

/**
 * Opt-in retry quality: severity routes, this gates. Errs without it retry
 * only when Transient and the scope budget allows.
 */
interface Retryable
{
    public bool $retryable { get; }
}
