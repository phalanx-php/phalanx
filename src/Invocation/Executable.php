<?php

declare(strict_types=1);

namespace Phalanx\Invocation;

/**
 * The work-unit marker. TOut is the generics keystone: scopes preserve the
 * declared outcome union, so PHPStan forbids dropping a child's Err unhandled.
 *
 * @template-covariant TOut
 */
interface Executable
{
}
