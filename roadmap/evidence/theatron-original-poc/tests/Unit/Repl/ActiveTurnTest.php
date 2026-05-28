<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Slice\ActiveTurn;
use Phalanx\Theatron\Demos\Repl\Slice\ToolCallSummary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActiveTurnTest extends TestCase
{
    #[Test]
    public function thinking_content_starts_null(): void
    {
        $turn = new ActiveTurn(userMessage: 'hello');

        self::assertNull($turn->thinkingContent);
    }

    #[Test]
    public function append_thinking_initializes_from_null(): void
    {
        $turn = new ActiveTurn(userMessage: 'hello');

        self::assertSame('hello', $turn->appendThinking('hello')->thinkingContent);
    }

    #[Test]
    public function append_thinking_concatenates(): void
    {
        $turn = new ActiveTurn(userMessage: 'q');
        $result = $turn->appendThinking('first ')->appendThinking('second');

        self::assertSame('first second', $result->thinkingContent);
    }

    #[Test]
    public function append_thinking_is_immutable(): void
    {
        $turn = new ActiveTurn(userMessage: 'q');
        $turn->appendThinking('delta');

        self::assertNull($turn->thinkingContent);
    }

    #[Test]
    public function finalize_carries_thinking_content_to_exchange(): void
    {
        $turn = new ActiveTurn(userMessage: 'q');
        $exchange = $turn->appendThinking('thought')->finalize();

        self::assertSame('thought', $exchange->thinkingContent);
    }

    #[Test]
    public function finalize_carries_null_thinking_when_none(): void
    {
        $turn = new ActiveTurn(userMessage: 'q');

        self::assertNull($turn->finalize()->thinkingContent);
    }

    #[Test]
    public function update_tool_call_with_result_content(): void
    {
        $call = new ToolCallSummary(toolName: 'inspect_terrain', argumentsSummary: 'location: Marathon', status: 'running');
        $turn = new ActiveTurn(userMessage: 'q');
        $turn = $turn->addToolCall($call);

        $updated = $turn->updateToolCall('inspect_terrain', 'ok', '{"elevation":"120m"}', 'text');

        self::assertSame('ok', $updated->toolCalls[0]->status);
        self::assertSame('{"elevation":"120m"}', $updated->toolCalls[0]->resultContent);
        self::assertSame('text', $updated->toolCalls[0]->resultType);
    }

    #[Test]
    public function update_tool_call_without_result_preserves_existing(): void
    {
        $call = new ToolCallSummary(
            toolName: 'consult_oracle',
            argumentsSummary: 'question: Attack?',
            status: 'running',
            resultContent: 'prior result',
            resultType: 'text',
        );
        $turn = (new ActiveTurn(userMessage: 'q'))->addToolCall($call);

        $updated = $turn->updateToolCall('consult_oracle', 'ok');

        self::assertSame('ok', $updated->toolCalls[0]->status);
        self::assertSame('prior result', $updated->toolCalls[0]->resultContent);
    }

    #[Test]
    public function update_tool_call_status_only(): void
    {
        $call = new ToolCallSummary(toolName: 'inspect_terrain', argumentsSummary: '', status: 'running');
        $turn = (new ActiveTurn(userMessage: 'q'))->addToolCall($call);

        $updated = $turn->updateToolCall('inspect_terrain', 'ok');

        self::assertSame('ok', $updated->toolCalls[0]->status);
        self::assertNull($updated->toolCalls[0]->resultContent);
    }

    #[Test]
    public function finalize_carries_tool_calls_with_results(): void
    {
        $call = new ToolCallSummary(toolName: 'inspect_terrain', argumentsSummary: 'location: Sparta');
        $turn = (new ActiveTurn(userMessage: 'q'))
            ->addToolCall($call)
            ->updateToolCall('inspect_terrain', 'ok', '{"defensibility":"high"}', 'text')
            ->appendText('Response text');

        $exchange = $turn->finalize();

        self::assertCount(1, $exchange->toolCalls);
        self::assertSame('ok', $exchange->toolCalls[0]->status);
        self::assertSame('{"defensibility":"high"}', $exchange->toolCalls[0]->resultContent);
    }

    #[Test]
    public function update_tool_call_nonexistent_name_leaves_list_unchanged(): void
    {
        $call = new ToolCallSummary(toolName: 'inspect_terrain', argumentsSummary: '', status: 'running');
        $turn = (new ActiveTurn(userMessage: 'q'))->addToolCall($call);

        $updated = $turn->updateToolCall('nonexistent_tool', 'ok', 'result', 'text');

        self::assertCount(1, $updated->toolCalls);
        self::assertSame('running', $updated->toolCalls[0]->status);
        self::assertNull($updated->toolCalls[0]->resultContent);
    }

    #[Test]
    public function update_tool_call_with_duplicate_names_updates_all(): void
    {
        $call1 = new ToolCallSummary(toolName: 'inspect_terrain', argumentsSummary: 'loc: A', status: 'running');
        $call2 = new ToolCallSummary(toolName: 'inspect_terrain', argumentsSummary: 'loc: B', status: 'running');
        $turn = (new ActiveTurn(userMessage: 'q'))
            ->addToolCall($call1)
            ->addToolCall($call2);

        $updated = $turn->updateToolCall('inspect_terrain', 'ok');

        self::assertSame('ok', $updated->toolCalls[0]->status);
        self::assertSame('ok', $updated->toolCalls[1]->status);
    }

    #[Test]
    public function update_tool_call_is_immutable(): void
    {
        $call = new ToolCallSummary(toolName: 'inspect_terrain', argumentsSummary: '', status: 'running');
        $turn = (new ActiveTurn(userMessage: 'q'))->addToolCall($call);

        $turn->updateToolCall('inspect_terrain', 'ok', 'result data', 'text');

        self::assertSame('running', $turn->toolCalls[0]->status);
        self::assertNull($turn->toolCalls[0]->resultContent);
    }
}
