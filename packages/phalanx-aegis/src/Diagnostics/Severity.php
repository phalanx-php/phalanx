<?php

declare(strict_types=1);

namespace Phalanx\Diagnostics;

/**
 * Severity of a {@see DoctorCheck} result.
 *
 * Required      — the environment must satisfy this probe; failure prevents the
 *                 application from functioning correctly.
 * Optional      — a capability that some packages may consume; failure surfaces
 *                 as a warning but does not gate health.
 * Informational — diagnostic context only; never counts as a failure, never
 *                 gates health.
 */
enum Severity: string
{
    case Required      = 'required';
    case Optional      = 'optional';
    case Informational = 'info';

    /**
     * Returns true when a failed probe with this severity gates health.
     * Future cases default to non-gating unless explicitly opted in here.
     */
    public function gates(): bool
    {
        return $this === self::Required;
    }
}
