<?php

declare(strict_types=1);

namespace Phalanx\Argos\Tests\Unit;

use Phalanx\Argos\NetworkConfig;
use PHPUnit\Framework\TestCase;

final class NetworkConfigTest extends TestCase
{
    public function test_defaults(): void
    {
        $config = new NetworkConfig();

        $this->assertSame(5.0, $config->defaultTimeout);
        $this->assertSame(50, $config->defaultConcurrency);
        $this->assertSame('ping', $config->pingBinary);
        $this->assertSame('255.255.255.255', $config->broadcastAddress);
        $this->assertSame(9, $config->wolPort);
    }

    public function test_custom_values(): void
    {
        $config = new NetworkConfig(
            defaultTimeout: 10.0,
            defaultConcurrency: 100,
            pingBinary: '/usr/bin/ping',
            broadcastAddress: '192.168.1.255',
            wolPort: 7,
        );

        $this->assertSame(10.0, $config->defaultTimeout);
        $this->assertSame(100, $config->defaultConcurrency);
        $this->assertSame('/usr/bin/ping', $config->pingBinary);
        $this->assertSame('192.168.1.255', $config->broadcastAddress);
        $this->assertSame(7, $config->wolPort);
    }
}
