<?php

declare(strict_types=1);

namespace Phalanx\Network\Tests\Unit;

use Phalanx\Network\Host;
use PHPUnit\Framework\TestCase;

final class HostTest extends TestCase
{
    public function test_creates_minimal_host(): void
    {
        $host = new Host(ip: '192.168.1.10');

        $this->assertSame('192.168.1.10', $host->ip);
        $this->assertNull($host->mac);
        $this->assertNull($host->hostname);
        $this->assertSame([], $host->services);
        $this->assertSame([], $host->metadata);
    }

    public function test_with_mac_returns_new_instance(): void
    {
        $host = new Host(ip: '192.168.1.10');
        $withMac = $host->withMac('AA:BB:CC:DD:EE:FF');

        $this->assertNull($host->mac);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $withMac->mac);
        $this->assertSame('192.168.1.10', $withMac->ip);
    }

    public function test_with_hostname_returns_new_instance(): void
    {
        $host = new Host(ip: '192.168.1.10');
        $named = $host->withHostname('printer.local');

        $this->assertSame('printer.local', $named->hostname);
    }

    public function test_with_metadata_merges(): void
    {
        $host = new Host(ip: '192.168.1.10', metadata: ['vendor' => 'Acme']);
        $enriched = $host->withMetadata(['model' => 'X100']);

        $this->assertSame(['vendor' => 'Acme', 'model' => 'X100'], $enriched->metadata);
    }

    public function test_creates_full_host(): void
    {
        $host = new Host(
            ip: '192.168.1.10',
            mac: 'AA:BB:CC:DD:EE:FF',
            hostname: 'mydevice.local',
            services: ['tcp/22', 'tcp/80'],
            metadata: ['type' => 'server'],
        );

        $this->assertSame('AA:BB:CC:DD:EE:FF', $host->mac);
        $this->assertSame('mydevice.local', $host->hostname);
        $this->assertCount(2, $host->services);
    }
}
