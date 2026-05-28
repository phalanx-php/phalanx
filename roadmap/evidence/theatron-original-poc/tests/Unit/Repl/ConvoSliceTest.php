<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Slice\ConvoSlice;
use Phalanx\Theatron\Demos\Repl\Slice\Exchange;
use Phalanx\Theatron\Demos\Repl\Slice\ToolCallSummary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConvoSliceTest extends TestCase
{
    #[Test]
    public function slice_key_is_repl_convo(): void
    {
        self::assertSame('repl.convo', (new ConvoSlice())->key);
    }

    #[Test]
    public function toggle_tool_expand_flips_tool_expanded_state(): void
    {
        $exchange = new Exchange(
            userMessage: 'hello',
            assistantResponse: 'world',
            summary: 'human: hello  ->  world',
            toolCalls: [
                new ToolCallSummary(toolName: 'read', argumentsSummary: 'path: /a', expanded: false),
                new ToolCallSummary(toolName: 'write', argumentsSummary: 'path: /b', expanded: false),
            ],
        );

        $slice = new ConvoSlice(lastExchange: $exchange);
        $updated = $slice->toggleToolExpand(1);

        self::assertTrue($updated->lastExchange->toolCalls[1]->expanded);
        self::assertFalse($updated->lastExchange->toolCalls[0]->expanded);
    }

    #[Test]
    public function toggle_tool_expand_is_immutable(): void
    {
        $exchange = new Exchange(
            userMessage: 'test',
            assistantResponse: 'reply',
            summary: 'human: test  ->  reply',
            toolCalls: [
                new ToolCallSummary(toolName: 'bash', argumentsSummary: 'cmd: ls', expanded: false),
            ],
        );

        $slice = new ConvoSlice(lastExchange: $exchange);
        $slice->toggleToolExpand(0);

        self::assertFalse($slice->lastExchange->toolCalls[0]->expanded);
    }

    #[Test]
    public function toggle_tool_expand_returns_same_when_no_last_exchange(): void
    {
        $slice = new ConvoSlice();

        $result = $slice->toggleToolExpand(0);

        self::assertSame($slice, $result);
    }

    #[Test]
    public function toggle_tool_expand_returns_same_for_invalid_tool_index(): void
    {
        $exchange = new Exchange(
            userMessage: 'q',
            assistantResponse: 'a',
            summary: 'human: q  ->  a',
            toolCalls: [],
        );

        $slice = new ConvoSlice(lastExchange: $exchange);
        $result = $slice->toggleToolExpand(0);

        self::assertSame($slice, $result);
    }

    #[Test]
    public function toggle_tool_expand_toggles_back_to_collapsed(): void
    {
        $exchange = new Exchange(
            userMessage: 'q',
            assistantResponse: 'a',
            summary: 'human: q  ->  a',
            toolCalls: [
                new ToolCallSummary(toolName: 'bash', argumentsSummary: '', expanded: true),
            ],
        );

        $slice = new ConvoSlice(lastExchange: $exchange);
        $updated = $slice->toggleToolExpand(0);

        self::assertFalse($updated->lastExchange->toolCalls[0]->expanded);
    }

    #[Test]
    public function show_thinking_defaults_to_true(): void
    {
        self::assertTrue((new ConvoSlice())->showThinking);
    }

    #[Test]
    public function toggle_thinking_flips_state(): void
    {
        $slice = new ConvoSlice();

        self::assertFalse($slice->toggleThinking()->showThinking);
    }

    #[Test]
    public function toggle_thinking_is_immutable(): void
    {
        $slice = new ConvoSlice();
        $slice->toggleThinking();

        self::assertTrue($slice->showThinking);
    }

    #[Test]
    public function toggle_thinking_round_trips(): void
    {
        $slice = new ConvoSlice();

        self::assertTrue($slice->toggleThinking()->toggleThinking()->showThinking);
    }
}
