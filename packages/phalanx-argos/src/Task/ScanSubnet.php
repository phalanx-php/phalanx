<?php

declare(strict_types=1);

namespace Phalanx\Argos\Task;

use Phalanx\Argos\ProbeResult;
use Phalanx\Argos\ProbeStrategy;
use Phalanx\Argos\Subnet;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

final readonly class ScanSubnet implements Executable
{
    public function __construct(
        private Subnet $subnet,
        private ProbeStrategy $strategy,
        private int $concurrency = 50,
    ) {
    }

    /** @return list<ProbeResult> */
    public function __invoke(ExecutionScope $scope): array
    {
        $ips = $this->subnet->ips();
        $strategy = $this->strategy;

        /** @var list<ProbeResult> $results */
        $results = $scope->map(
            items: $ips,
            fn: static fn(string $ip): Scopeable => $strategy->forHost($ip),
            limit: $this->concurrency,
        );

        return array_values(array_filter(
            $results,
            static fn(ProbeResult $r): bool => $r->reachable,
        ));
    }
}
