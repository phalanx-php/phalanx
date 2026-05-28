<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Event\ToolCallEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolCallEventTest extends TestCase
{
    #[Test]
    public function to_summary_running_tool_has_running_status(): void
    {
        $event = new ToolCallEvent(
            toolName: 'read_file',
            argumentsSummary: 'path: /tmp/foo.php',
            started: true,
        );

        $summary = $event->toSummary();

        self::assertSame('read_file', $summary->toolName);
        self::assertSame('path: /tmp/foo.php', $summary->argumentsSummary);
        self::assertSame('running', $summary->status);
        self::assertNull($summary->resultContent);
    }

    #[Test]
    public function to_summary_completed_tool_with_result_content(): void
    {
        $event = new ToolCallEvent(
            toolName: 'bash',
            argumentsSummary: 'cmd: ls',
            started: false,
            result: 'ok',
            resultContent: 'file1.php\nfile2.php',
            resultType: 'text',
        );

        $summary = $event->toSummary();

        self::assertSame('ok', $summary->status);
        self::assertSame('file1.php\nfile2.php', $summary->resultContent);
        self::assertSame('text', $summary->resultType);
    }

    #[Test]
    public function to_summary_completed_tool_without_result_content(): void
    {
        $event = new ToolCallEvent(
            toolName: 'write_file',
            argumentsSummary: 'path: /a',
            started: false,
            result: 'ok',
        );

        $summary = $event->toSummary();

        self::assertSame('ok', $summary->status);
        self::assertNull($summary->resultContent);
        self::assertNull($summary->resultType);
    }

    #[Test]
    public function to_summary_defaults_result_type_to_text(): void
    {
        $event = new ToolCallEvent(
            toolName: 'grep',
            argumentsSummary: 'pattern: foo',
            started: false,
            result: 'ok',
            resultContent: 'some output',
            resultType: null,
        );

        $summary = $event->toSummary();

        self::assertSame('text', $summary->resultType);
    }

    #[Test]
    public function to_summary_uses_result_as_status_when_not_started(): void
    {
        $event = new ToolCallEvent(
            toolName: 'fetch',
            argumentsSummary: '',
            started: false,
            result: 'error',
        );

        $summary = $event->toSummary();

        self::assertSame('error', $summary->status);
    }

    #[Test]
    public function to_summary_defaults_status_to_ok_when_result_is_null(): void
    {
        $event = new ToolCallEvent(
            toolName: 'noop',
            argumentsSummary: '',
            started: false,
            result: null,
        );

        $summary = $event->toSummary();

        self::assertSame('ok', $summary->status);
    }
}
