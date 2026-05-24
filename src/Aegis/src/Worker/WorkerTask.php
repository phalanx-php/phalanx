<?php

declare(strict_types=1);

namespace Phalanx\Worker;

use Phalanx\Task\Scopeable;
use Phalanx\Task\Traceable;

/**
 * Named invokable that may cross the Hydra process boundary.
 *
 * Worker tasks are data envelopes plus behavior. They must not capture
 * process-local resources such as scopes, leases, sockets, streams,
 * transactions, service instances, or closures.
 *
 * @method mixed __invoke(WorkerScope $scope)
 */
interface WorkerTask extends Scopeable, Traceable
{
}
