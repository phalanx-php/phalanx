<?php

declare(strict_types=1);

namespace Phalanx\Registry;

/**
 * Query-scope axis for registry-style accessors that distinguish between
 * "this worker process only" and "the whole server cluster".
 *
 * Used by ServerStats and any future registry/count accessor where the
 * caller asks "live count of X" and the answer differs depending on
 * whether the question is local-process or process-spanning. OpenSwoole's
 * master process tracks server-wide counts natively; the per-worker
 * answer is local registry size or filtered iteration over getClientList.
 */
enum RegistryScope: string
{
    case Worker = 'worker';
    case Server = 'server';
}
