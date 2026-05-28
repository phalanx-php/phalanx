<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Capstone;

use Phalanx\Theatron\Demos\Capstone\Slice\AgentInfo;
use Phalanx\Theatron\Demos\Capstone\Slice\AgentRegistrySlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentRegistrySliceTest extends TestCase
{
    #[Test]
    public function with_status_updates_known_agent(): void
    {
        $slice = new AgentRegistrySlice([
            'a' => new AgentInfo(id: 'a', name: 'Thales', role: 'research'),
        ]);

        $updated = $slice->withStatus('a', 'working');

        self::assertSame('working', $updated->agents['a']->status);
        self::assertSame('offline', $slice->agents['a']->status);
    }

    #[Test]
    public function with_status_returns_same_instance_for_unknown_agent(): void
    {
        $slice = new AgentRegistrySlice([
            'a' => new AgentInfo(id: 'a', name: 'Thales', role: 'research'),
        ]);

        $result = $slice->withStatus('nonexistent', 'online');

        self::assertSame($slice, $result);
    }

    #[Test]
    public function slice_key_is_capstone_agents(): void
    {
        self::assertSame('capstone.agents', (new AgentRegistrySlice())->key);
    }

    #[Test]
    public function empty_by_default(): void
    {
        $slice = new AgentRegistrySlice();

        self::assertSame([], $slice->agents);
    }
}
