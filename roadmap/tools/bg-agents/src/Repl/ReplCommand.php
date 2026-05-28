<?php

declare(strict_types=1);

namespace BgAgents\Repl;

/**
 * Marker interface for the parsed-command discriminated union.
 *
 * One concrete class per repl verb so dispatch becomes a simple match on
 * type. Keeps the parser pure (no closures inside) and handlers trivially
 * testable in isolation.
 */
interface ReplCommand
{
}
