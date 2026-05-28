<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Slice\Exchange;
use Phalanx\Theatron\Demos\Repl\Slice\ToolCallSummary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExchangeTest extends TestCase
{
    #[Test]
    public function with_tool_calls_replaces_calls_immutably(): void
    {
        $exchange = new Exchange(
            userMessage: 'run tests',
            assistantResponse: 'done',
            summary: 'human: run tests  ->  done',
            toolCalls: [],
        );

        $calls = [
            new ToolCallSummary(toolName: 'bash', argumentsSummary: 'cmd: composer test'),
        ];

        $updated = $exchange->withToolCalls($calls);

        self::assertCount(1, $updated->toolCalls);
        self::assertSame('bash', $updated->toolCalls[0]->toolName);
        self::assertSame([], $exchange->toolCalls);
    }

    #[Test]
    public function summarize_truncates_long_user_message(): void
    {
        $long = str_repeat('a', 30);

        $result = Exchange::summarize($long, 'reply');

        self::assertStringStartsWith('human: aaa', $result);
        self::assertStringContainsString('...', $result);
        self::assertStringContainsString('reply', $result);
    }

    #[Test]
    public function summarize_truncates_long_assistant_response(): void
    {
        $long = str_repeat('b', 60);

        $result = Exchange::summarize('hi', $long);

        self::assertStringStartsWith('human: hi', $result);
        self::assertStringEndsWith('...', $result);
    }

    #[Test]
    public function summarize_handles_empty_response(): void
    {
        $result = Exchange::summarize('question', '');

        self::assertSame('human: question', $result);
    }

    #[Test]
    public function summarize_uses_first_line_of_response(): void
    {
        $result = Exchange::summarize('q', "first line\nsecond line\nthird");

        self::assertStringContainsString('first line', $result);
        self::assertStringNotContainsString('second line', $result);
    }

    #[Test]
    public function summarize_short_messages_not_truncated(): void
    {
        $result = Exchange::summarize('hi', 'hello');

        self::assertSame('human: hi  →  hello', $result);
    }
}
