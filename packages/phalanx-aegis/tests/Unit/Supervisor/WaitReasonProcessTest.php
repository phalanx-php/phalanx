<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use Phalanx\Supervisor\WaitKind;
use Phalanx\Supervisor\WaitReason;
use PHPUnit\Framework\TestCase;

final class WaitReasonProcessTest extends TestCase
{
    public function testProcessUsesProcessKind(): void
    {
        $reason = WaitReason::process('ping -c 1 192.168.1.1');

        self::assertSame(WaitKind::Process, $reason->kind);
    }

    public function testProcessKeepsOnlyHeadOfCommand(): void
    {
        $reason = WaitReason::process('/usr/sbin/ping -c 1 -W 2 192.168.1.1');

        self::assertSame('/usr/sbin/ping', $reason->detail);
    }

    public function testProcessAppendsDetailWhenProvided(): void
    {
        $reason = WaitReason::process('ping 192.168.1.1', 'host probe');

        self::assertSame('ping (host probe)', $reason->detail);
    }

    public function testProcessHandlesEmptyCommand(): void
    {
        $reason = WaitReason::process('');

        self::assertSame('', $reason->detail);
    }

    public function testStartedAtIsRecorded(): void
    {
        $before = microtime(true);
        $reason = WaitReason::process('uname');
        $after = microtime(true);

        self::assertGreaterThanOrEqual($before, $reason->startedAt);
        self::assertLessThanOrEqual($after, $reason->startedAt);
    }
}
