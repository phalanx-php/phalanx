<?php

declare(strict_types=1);

namespace Phalanx\Pool;

/**
 * Marker for framework-owned values whose identity may be reused after a borrow scope exits.
 */
interface BorrowedValue
{
}
