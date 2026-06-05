<?php

declare(strict_types=1);

namespace Phalanx\Network\Task;

use Phalanx\Mark\Mark;
use Phalanx\Recovery\Recoverable;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Scope\TaskScope;
use Phalanx\System\DnsResolver;
use Phalanx\Task\Scopeable;

final class ResolveHostname implements Scopeable, Recoverable
{
    public RecoveryPlan $recovery {
        get {
            return $this->recoveryPlan();
        }
    }

    public function __construct(
        private readonly string $hostname,
        private readonly float $timeoutSeconds = 5.0,
    ) {
    }

    /** @return list<string> */
    public function __invoke(TaskScope $scope): array
    {
        $resolver = new DnsResolver(defaultTimeout: $this->timeoutSeconds);
        $result = $resolver->resolveAll($scope, $this->hostname);

        return $result->addresses;
    }

    private function recoveryPlan(): RecoveryPlan
    {
        return RecoveryPlan::failFast(deadline: Mark::s($this->timeoutSeconds));
    }
}
