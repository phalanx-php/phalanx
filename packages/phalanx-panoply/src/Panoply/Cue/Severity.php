<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue;

/**
 * Diagnostic severity for {@see Runtime\Notice}, {@see Runtime\Warning},
 * and {@see Runtime\Error}.
 */
enum Severity: string
{
    case Notice  = 'notice';
    case Warning = 'warning';
    case Error   = 'error';
}
