<?php

declare(strict_types=1);

namespace Phalanx\Network\Task;

use Phalanx\ExecutionScope;
use Phalanx\Network\ProbeResult;
use Phalanx\Task\Executable;

final readonly class ScanPorts implements Executable
{
    /** @param list<int> $ports */
    public function __construct(
        private string $ip,
        private array $ports,
        private float $perPortTimeout = 1.0,
        private int $concurrency = 20,
    ) {}

    public function __invoke(ExecutionScope $scope): array
    {
        $results = $scope->map(
            items: $this->ports,
            fn: fn(int $port): ProbeResult => $scope->execute(
                new ProbePort($this->ip, $port, $this->perPortTimeout),
            ),
            limit: $this->concurrency,
        );

        return array_values(array_filter(
            $results,
            static fn(ProbeResult $r): bool => $r->reachable,
        ));
    }
}
