<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Phalanx\Enigma\CommandResult;
use Phalanx\Enigma\Exception\SshException;

final class CommandResultTest extends TestCase
{
    public function test_successful_result(): void
    {
        $result = new CommandResult(0, "hello\nworld\n", '', 123.4);

        self::assertTrue($result->successful);
        self::assertSame(0, $result->exitCode);
        self::assertSame(123.4, $result->durationMs);
    }

    public function test_failed_result(): void
    {
        $result = new CommandResult(1, '', 'command not found', 50.0);

        self::assertFalse($result->successful);
    }

    public function test_lines_splits_stdout(): void
    {
        $result = new CommandResult(0, "line1\nline2\nline3\n", '', 10.0);

        self::assertSame(['line1', 'line2', 'line3'], $result->lines);
    }

    public function test_lines_handles_no_trailing_newline(): void
    {
        $result = new CommandResult(0, "one\ntwo", '', 10.0);

        self::assertSame(['one', 'two'], $result->lines);
    }

    public function test_throw_if_failed_throws_on_failure(): void
    {
        $result = new CommandResult(127, '', 'bash: foo: command not found', 10.0);

        $this->expectException(SshException::class);
        $this->expectExceptionMessage('exit 127');
        $result->throwIfFailed();
    }

    public function test_throw_if_failed_returns_self_on_success(): void
    {
        $result = new CommandResult(0, 'ok', '', 10.0);

        self::assertSame($result, $result->throwIfFailed());
    }
}
