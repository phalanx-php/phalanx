<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Slice\ToolCallSummary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolCallSummaryTest extends TestCase
{
    #[Test]
    public function with_result_sets_content_and_type(): void
    {
        $summary = new ToolCallSummary(toolName: 'read_file', argumentsSummary: 'path: /tmp/foo');

        $updated = $summary->withResult('file contents here', 'code');

        self::assertSame('file contents here', $updated->resultContent);
        self::assertSame('code', $updated->resultType);
    }

    #[Test]
    public function with_result_defaults_type_to_text(): void
    {
        $summary = new ToolCallSummary(toolName: 'bash', argumentsSummary: 'cmd: ls');

        $updated = $summary->withResult('output');

        self::assertSame('text', $updated->resultType);
    }

    #[Test]
    public function with_result_is_immutable(): void
    {
        $summary = new ToolCallSummary(toolName: 'grep', argumentsSummary: 'pattern: foo');

        $summary->withResult('match line 1');

        self::assertNull($summary->resultContent);
        self::assertNull($summary->resultType);
    }

    #[Test]
    public function with_expanded_toggles_expansion_state(): void
    {
        $summary = new ToolCallSummary(toolName: 'edit', argumentsSummary: 'file: a.php');

        $expanded = $summary->withExpanded(true);
        $collapsed = $expanded->withExpanded(false);

        self::assertTrue($expanded->expanded);
        self::assertFalse($collapsed->expanded);
    }

    #[Test]
    public function with_expanded_is_immutable(): void
    {
        $summary = new ToolCallSummary(toolName: 'edit', argumentsSummary: '');

        $summary->withExpanded(true);

        self::assertFalse($summary->expanded);
    }

    #[Test]
    public function with_status_sets_status(): void
    {
        $summary = new ToolCallSummary(toolName: 'fetch', argumentsSummary: 'url: /api');

        $updated = $summary->withStatus('running');

        self::assertSame('running', $updated->status);
        self::assertNull($summary->status);
    }
}
