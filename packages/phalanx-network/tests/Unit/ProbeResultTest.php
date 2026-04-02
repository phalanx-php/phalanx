<?php

declare(strict_types=1);

namespace Phalanx\Network\Tests\Unit;

use Phalanx\Network\ProbeResult;
use PHPUnit\Framework\TestCase;

final class ProbeResultTest extends TestCase
{
    public function test_reachable_result(): void
    {
        $result = new ProbeResult(
            ip: '192.168.1.10',
            reachable: true,
            latencyMs: 1.5,
            method: 'tcp',
            port: 22,
        );

        $this->assertTrue($result->reachable);
        $this->assertSame('192.168.1.10', $result->ip);
        $this->assertSame(1.5, $result->latencyMs);
        $this->assertSame('tcp', $result->method);
        $this->assertSame(22, $result->port);
    }

    public function test_unreachable_result(): void
    {
        $result = new ProbeResult(
            ip: '192.168.1.99',
            reachable: false,
            method: 'icmp',
        );

        $this->assertFalse($result->reachable);
        $this->assertNull($result->latencyMs);
        $this->assertNull($result->port);
    }

    public function test_udp_result_with_response_data(): void
    {
        $result = new ProbeResult(
            ip: '192.168.1.50',
            reachable: true,
            latencyMs: 3.2,
            method: 'udp',
            port: 8080,
            responseData: "\x00\x01\x02",
        );

        $this->assertSame("\x00\x01\x02", $result->responseData);
    }
}
