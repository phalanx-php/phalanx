<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Effect\Decision;

/**
 * Authorization verdict issued by the Decision factory. Drives which
 * fields are populated on the Decision value object and which predicates
 * return true.
 */
enum Verdict: string
{
    case Granted = 'granted';
    case Denied = 'denied';
    case Paused = 'paused';
}
