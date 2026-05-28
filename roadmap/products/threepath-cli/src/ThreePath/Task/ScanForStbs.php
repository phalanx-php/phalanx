<?php

declare(strict_types=1);

namespace ThreePath\Task;

use IPLib\Range\Subnet;
use Phalanx\Archon\Scanner;
use Phalanx\Archon\ScanObserver;
use Phalanx\ExecutionScope;
use Phalanx\Task\Scopeable;
use ThreePath\StbConfig;
use ThreePath\StbResponse;

final class ScanForStbs implements Scanner
{
    private ?ScanObserver $observer = null;

    public function __construct(private readonly string $cidr) {}

    public function withObserver(ScanObserver $observer): static
    {
        $clone = clone $this;
        $clone->observer = $observer;
        return $clone;
    }

    /**
     * @return list<StbResponse>
     */
    public function __invoke(ExecutionScope $scope): array
    {
        /** @var StbConfig $config */
        $config = $scope->service(StbConfig::class);

        $range = Subnet::parseString($this->cidr)
            ?? throw new \InvalidArgumentException("Invalid CIDR: {$this->cidr}");

        $ips = self::generateIps($range);
        $observer = $this->observer;
        $observer?->onStart(count($ips));

        $start = hrtime(true);

        $results = $scope->map(
            items: $ips,
            fn: static fn(string $ip): Scopeable => new PingStb($ip),
            limit: $config->scanConcurrency,
            onEach: $observer !== null
                ? static function (StbResponse $r) use ($observer): void {
                    if ($r->success) {
                        $observer->onHit($r);
                    } else {
                        $observer->onMiss($r);
                    }
                }
                : null,
        );

        $observer?->onDone((hrtime(true) - $start) / 1e9);

        return array_values(array_filter(
            $results,
            static fn(StbResponse $r): bool => $r->success,
        ));
    }

    /**
     * @return array<int, string>
     */
    private static function generateIps(Subnet $range): array
    {
        $ips = [];
        $current = $range->getStartAddress();
        $end = $range->getEndAddress();

        while ($current !== null && $current->getComparableString() <= $end->getComparableString()) {
            $ips[] = $current->toString();
            $current = $current->getNextAddress();
        }

        return $ips;
    }
}
