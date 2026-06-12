<?php

declare(strict_types=1);

namespace Phalanx\Engine;

use Exception;
use Phalanx\Err\Fault;

/**
 * Kernel-internal unwinding signal between frame boundaries the engine
 * controls. Never userland-visible: it leaves the kernel only as
 * FaultEscaped past the root scope.
 */
final class FaultSignal extends Exception
{
    public function __construct(
        private(set) Fault $fault,
    ) {
        parent::__construct('phalanx kernel fault signal');
    }
}
