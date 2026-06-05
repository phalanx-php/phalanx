<?php

declare(strict_types=1);

namespace Phalanx\Network\Tests\Unit;

use Phalanx\Network\Task\WakeHost;
use PHPUnit\Framework\TestCase;

final class WakeHostTest extends TestCase
{
    public function test_constructs_with_short_mac(): void
    {
        // MAC validation happens in __invoke, not constructor.
        // Invalid MACs are caught at execution time when the scope runs the task.
        $task = new WakeHost('AA:BB:CC');
        $this->assertInstanceOf(WakeHost::class, $task);
    }

    public function test_accepts_colon_separated_mac(): void
    {
        $task = new WakeHost('AA:BB:CC:DD:EE:FF');
        $this->assertInstanceOf(WakeHost::class, $task);
    }

    public function test_accepts_dash_separated_mac(): void
    {
        $task = new WakeHost('AA-BB-CC-DD-EE-FF');
        $this->assertInstanceOf(WakeHost::class, $task);
    }

    public function test_accepts_bare_mac(): void
    {
        $task = new WakeHost('AABBCCDDEEFF');
        $this->assertInstanceOf(WakeHost::class, $task);
    }
}
