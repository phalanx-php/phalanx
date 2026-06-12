<?php

declare(strict_types=1);

namespace Phalanx\Err;

use RuntimeException;

/**
 * An unabsorbed Fault crossed the ROOT scope. Boundary layers render this;
 * with no boundary present (tests, bare scripts) it surfaces as this
 * exception. Executable bodies still cannot catch it: the no-try/catch rule
 * is userland-body policy, and the kernel converts before any body sees it.
 */
final class FaultEscaped extends RuntimeException
{
    public function __construct(
        private(set) Fault $fault,
    ) {
        parent::__construct(sprintf(
            'Fault escaped the root scope: %s%s',
            $fault->chain[0]->class,
            $fault->operation === null ? '' : " during {$fault->operation}",
        ));
    }
}
