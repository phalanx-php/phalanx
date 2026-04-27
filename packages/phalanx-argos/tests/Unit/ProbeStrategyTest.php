<?php

declare(strict_types=1);

namespace Phalanx\Argos\Tests\Unit;

use Phalanx\Argos\ProbeStrategy;
use Phalanx\Argos\Task\PingHost;
use Phalanx\Argos\Task\ProbePort;
use Phalanx\Argos\Task\ProbeUdp;
use PHPUnit\Framework\TestCase;

final class ProbeStrategyTest extends TestCase
{
    public function test_tcp_strategy_creates_probe_port(): void
    {
        $strategy = ProbeStrategy::tcp(port: 22, timeout: 3.0);
        $task = $strategy->forHost('192.168.1.10');

        $this->assertInstanceOf(ProbePort::class, $task);
        $this->assertSame(ProbePort::class, $strategy->taskClass());
    }

    public function test_udp_strategy_creates_probe_udp(): void
    {
        $strategy = ProbeStrategy::udp(port: 8080, payload: "\x00\x01");
        $task = $strategy->forHost('10.0.0.5');

        $this->assertInstanceOf(ProbeUdp::class, $task);
    }

    public function test_ping_strategy_creates_ping_host(): void
    {
        $strategy = ProbeStrategy::ping(timeout: 1.0, retries: 3);
        $task = $strategy->forHost('172.16.0.1');

        $this->assertInstanceOf(PingHost::class, $task);
    }

    public function test_strategy_is_immutable(): void
    {
        $strategy = ProbeStrategy::tcp(port: 80);

        $task1 = $strategy->forHost('192.168.1.1');
        $task2 = $strategy->forHost('192.168.1.2');

        $this->assertNotSame($task1, $task2);
    }
}
