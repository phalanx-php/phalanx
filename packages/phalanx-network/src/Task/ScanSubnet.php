<?php

declare(strict_types=1);

namespace Phalanx\Network\Task;

use Phalanx\ExecutionScope;
use Phalanx\Network\ProbeResult;
use Phalanx\Network\ProbeStrategy;
use Phalanx\Network\Subnet;
use Phalanx\Task\Executable;

final readonly class ScanSubnet implements Executable
{
    public function __construct(
        private Subnet $subnet,
        private ProbeStrategy $strategy,
        private int $concurrency = 50,
    ) {}

    public function __invoke(ExecutionScope $scope): array
    {
        $ips = $this->subnet->ips();

        $results = $scope->map(
            items: $ips,
            fn: fn(string $ip): ProbeResult => $scope->execute($this->strategy->forHost($ip)),
            limit: $this->concurrency,
        );

        return array_values(array_filter(
            $results,
            static fn(ProbeResult $r): bool => $r->reachable,
        ));
    }
}
