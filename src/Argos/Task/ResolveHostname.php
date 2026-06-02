<?php

declare(strict_types=1);

namespace Phalanx\Argos\Task;

use Phalanx\Scope\TaskScope;
use Phalanx\System\DnsResolver;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Scopeable;

final class ResolveHostname implements Scopeable, HasTimeout
{
    public float $timeout {
        get => $this->timeoutSeconds;
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
}
