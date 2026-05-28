<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Store;

use Phalanx\Theatron\Demos\Capstone\Slice\AgentInfo;
use Phalanx\Theatron\Demos\Capstone\Slice\AgentRegistrySlice;
use Phalanx\Theatron\Store\MemorySliceTable;
use Phalanx\Theatron\Store\StoreException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MemorySliceTableTest extends TestCase
{
    #[Test]
    public function reads_initial_empty_state(): void
    {
        $table = new MemorySliceTable(AgentRegistrySlice::class);

        $slice = $table->read();

        self::assertInstanceOf(AgentRegistrySlice::class, $slice);
        self::assertSame([], $slice->agents);
    }

    #[Test]
    public function write_and_read_round_trips(): void
    {
        $table = new MemorySliceTable(AgentRegistrySlice::class);
        $slice = new AgentRegistrySlice([
            'a' => new AgentInfo(id: 'a', name: 'Thales', role: 'research', status: 'online'),
        ]);

        $table->write($slice);
        $read = $table->read();

        self::assertSame($slice, $read);
    }

    #[Test]
    public function write_rejects_wrong_slice_class(): void
    {
        $table = new MemorySliceTable(AgentRegistrySlice::class);

        $this->expectException(StoreException::class);
        $table->write(new \Phalanx\Theatron\Demos\Capstone\Slice\TaskBoardSlice());
    }

    #[Test]
    public function matches_returns_true_for_same_instance(): void
    {
        $table = new MemorySliceTable(AgentRegistrySlice::class);
        $slice = new AgentRegistrySlice();

        self::assertTrue($table->matches($slice, $slice));
    }

    #[Test]
    public function matches_returns_false_for_different_instances(): void
    {
        $table = new MemorySliceTable(AgentRegistrySlice::class);
        $a = new AgentRegistrySlice();
        $b = new AgentRegistrySlice(['x' => new AgentInfo(id: 'x', name: 'X', role: 'r')]);

        self::assertFalse($table->matches($a, $b));
    }
}
