<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\System;

use Phalanx\System\CommandResult;
use Phalanx\System\SystemCommandException;
use PHPUnit\Framework\TestCase;

final class CommandResultTest extends TestCase
{
    public function testSuccessfulHookFlipsOnZeroExit(): void
    {
        $result = new CommandResult('echo hi', 0, "hi\n", 1.5);

        self::assertTrue($result->successful);
        self::assertSame('hi'.PHP_EOL, $result->output);
        self::assertSame(0, $result->exitCode);
        self::assertSame(0, $result->signal);
    }

    public function testSuccessfulHookFlipsOffOnNonZeroExit(): void
    {
        $result = new CommandResult('false', 1, '', 0.2);

        self::assertFalse($result->successful);
    }

    public function testThrowIfFailedNoOpsOnSuccess(): void
    {
        $result = new CommandResult('true', 0, '', 0.1);

        $result->throwIfFailed();

        self::assertTrue($result->successful);
    }

    public function testThrowIfFailedRaisesOnNonZeroExit(): void
    {
        $result = new CommandResult('false', 1, '', 0.1);

        $this->expectException(SystemCommandException::class);
        $this->expectExceptionMessage("command 'false' exited with code 1");

        $result->throwIfFailed();
    }

    public function testThrowIfFailedIncludesContextWhenProvided(): void
    {
        $result = new CommandResult('ping', 2, "host unreachable\n", 12.0);

        $this->expectException(SystemCommandException::class);
        $this->expectExceptionMessage("ping failed: command 'ping' exited with code 2: host unreachable");

        $result->throwIfFailed('ping failed');
    }

    public function testThrowIfFailedIncludesSignalWhenSet(): void
    {
        $result = new CommandResult('long-running', 137, '', 5.0, signal: 9);

        try {
            $result->throwIfFailed();
            self::fail('expected exception');
        } catch (SystemCommandException $e) {
            self::assertStringContainsString('exited with code 137', $e->getMessage());
            self::assertStringContainsString('signal 9', $e->getMessage());
        }
    }
}
