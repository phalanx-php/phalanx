<?php

declare(strict_types=1);

namespace Phalanx\Network\Tests\Unit;

use Phalanx\Network\Subnet;
use PHPUnit\Framework\TestCase;

final class SubnetTest extends TestCase
{
    public function test_expands_24_subnet(): void
    {
        $subnet = new Subnet('192.168.1.0/24');

        $ips = $subnet->ips();

        $this->assertCount(254, $ips);
        $this->assertSame('192.168.1.1', $ips[0]);
        $this->assertSame('192.168.1.254', $ips[253]);
    }

    public function test_expands_28_subnet(): void
    {
        $subnet = new Subnet('10.0.0.0/28');

        $ips = $subnet->ips();

        $this->assertCount(14, $ips);
        $this->assertSame('10.0.0.1', $ips[0]);
        $this->assertSame('10.0.0.14', $ips[13]);
    }

    public function test_excludes_network_and_broadcast(): void
    {
        $subnet = new Subnet('192.168.1.0/24');

        $this->assertNotContains('192.168.1.0', $subnet->ips());
        $this->assertNotContains('192.168.1.255', $subnet->ips());
    }

    public function test_contains_checks_membership(): void
    {
        $subnet = new Subnet('192.168.1.0/24');

        $this->assertTrue($subnet->contains('192.168.1.50'));
        $this->assertFalse($subnet->contains('192.168.2.50'));
    }

    public function test_from_range_creates_24_subnet(): void
    {
        $subnet = Subnet::fromRange('192.168.1');

        $this->assertCount(254, $subnet->ips());
        $this->assertSame('192.168.1.0/24', $subnet->cidr);
    }

    public function test_rejects_missing_cidr(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Subnet('192.168.1.0');
    }

    public function test_rejects_too_large_subnet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Subnet('10.0.0.0/8');
    }

    public function test_rejects_too_small_subnet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Subnet('10.0.0.0/31');
    }

    public function test_count_returns_host_count(): void
    {
        $subnet = new Subnet('192.168.1.0/24');

        $this->assertSame(254, $subnet->count());
    }
}
