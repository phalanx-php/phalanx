<?php

declare(strict_types=1);

namespace Phalanx\Network;

use IPLib\Factory;
use IPLib\Range\RangeInterface;
use IPLib\Range\Subnet as IpLibSubnet;

final readonly class Subnet
{
    private RangeInterface $range;

    public function __construct(
        public string $cidr,
    ) {
        $parsed = IpLibSubnet::parseString($cidr);

        if ($parsed === null) {
            throw new \InvalidArgumentException("Invalid CIDR notation: $cidr");
        }

        $this->range = $parsed;
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

    /** @return \Generator<int, string> */
    public function addresses(): \Generator
    {
        $current = $this->range->getStartAddress();
        $end = $this->range->getEndAddress();

        if ($current === null || $end === null) {
            return;
        }

        $endStr = $end->getComparableString();
        $current = $current->getNextAddress();

        while ($current !== null && $current->getComparableString() < $endStr) {
            yield $current->toString();
            $current = $current->getNextAddress();
        }
    }

    /** @return list<string> */
    public function ips(): array
    {
        return iterator_to_array($this->addresses(), false);
    }

    public function count(): int
    {
        return iterator_count($this->addresses());
    }

    public function contains(string $ip): bool
    {
        $address = Factory::parseAddressString($ip);

        if ($address === null) {
            return false;
        }

        return $this->range->contains($address);
    }

    public function startAddress(): string
    {
        return $this->range->getStartAddress()->toString();
    }

    public function endAddress(): string
    {
        return $this->range->getEndAddress()->toString();
    }

    public function range(): RangeInterface
    {
        return $this->range;
    }
}
