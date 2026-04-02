<?php

declare(strict_types=1);

namespace Phalanx\Network;

final readonly class Subnet
{
    /** @param list<string> $ips Precomputed list of IPs in the subnet */
    private array $ips;

    public function __construct(
        public string $cidr,
    ) {
        $this->ips = self::expand($cidr);
    }

    public static function fromRange(string $base, int $start = 1, int $end = 254): self
    {
        $parts = explode('.', $base);
        if (count($parts) < 3) {
            throw new \InvalidArgumentException("Base must be at least 3 octets: $base");
        }

        $prefix = implode('.', array_slice($parts, 0, 3));

        return new self("$prefix.0/24");
    }

    /** @return list<string> */
    public function ips(): array
    {
        return $this->ips;
    }

    public function count(): int
    {
        return count($this->ips);
    }

    public function contains(string $ip): bool
    {
        return in_array($ip, $this->ips, true);
    }

    /** @return list<string> */
    private static function expand(string $cidr): array
    {
        if (!str_contains($cidr, '/')) {
            throw new \InvalidArgumentException("CIDR notation required: $cidr");
        }

        [$network, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        if ($bits < 16 || $bits > 30) {
            throw new \InvalidArgumentException("CIDR mask must be between /16 and /30: /$bits");
        }

        $networkLong = ip2long($network);
        if ($networkLong === false) {
            throw new \InvalidArgumentException("Invalid network address: $network");
        }

        $mask = -1 << (32 - $bits);
        $networkLong &= $mask;
        $broadcast = $networkLong | ~$mask;

        $ips = [];
        for ($i = $networkLong + 1; $i < $broadcast; $i++) {
            $ips[] = long2ip($i);
        }

        return $ips;
    }
}
