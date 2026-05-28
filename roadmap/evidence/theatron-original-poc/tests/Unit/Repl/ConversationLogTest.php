<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\ConversationLog;
use Phalanx\Theatron\Demos\Repl\Slice\Exchange;
use Phalanx\Theatron\Demos\Repl\Slice\ToolCallSummary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationLogTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/theatron-test-' . uniqid() . '.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    #[Test]
    public function append_writes_jsonl_line_and_returns_offset(): void
    {
        $log = new ConversationLog($this->path);

        $offset0 = $log->append(self::exchange('hello', 'world'));
        $offset1 = $log->append(self::exchange('foo', 'bar'));

        self::assertSame(0, $offset0);
        self::assertSame(1, $offset1);
        self::assertFileExists($this->path);

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertCount(2, $lines);
    }

    #[Test]
    public function read_at_returns_exchange_at_offset(): void
    {
        $log = new ConversationLog($this->path);
        $log->append(self::exchange('first', 'alpha'));
        $log->append(self::exchange('second', 'beta'));

        $result = $log->readAt(1);

        self::assertNotNull($result);
        self::assertSame('second', $result->userMessage);
        self::assertSame('beta', $result->assistantResponse);
    }

    #[Test]
    public function read_at_returns_null_for_out_of_range(): void
    {
        $log = new ConversationLog($this->path);
        $log->append(self::exchange('only', 'one'));

        self::assertNull($log->readAt(5));
    }

    #[Test]
    public function read_at_returns_null_when_file_missing(): void
    {
        $log = new ConversationLog('/tmp/nonexistent-' . uniqid() . '.jsonl');

        self::assertNull($log->readAt(0));
    }

    #[Test]
    public function read_by_id_finds_exchange_by_ulid(): void
    {
        $log = new ConversationLog($this->path);
        $exchange = self::exchange('target', 'found');
        $log->append(self::exchange('decoy', 'skip'));
        $log->append($exchange);

        $result = $log->readById($exchange->id);

        self::assertNotNull($result);
        self::assertSame('target', $result->userMessage);
        self::assertSame($exchange->id, $result->id);
    }

    #[Test]
    public function read_by_id_returns_null_for_missing_id(): void
    {
        $log = new ConversationLog($this->path);
        $log->append(self::exchange('something', 'else'));

        self::assertNull($log->readById('nonexistent-id'));
    }

    #[Test]
    public function last_n_returns_final_exchanges(): void
    {
        $log = new ConversationLog($this->path);
        $log->append(self::exchange('a', '1'));
        $log->append(self::exchange('b', '2'));
        $log->append(self::exchange('c', '3'));

        $result = $log->lastN(2);

        self::assertCount(2, $result);
        self::assertSame('b', $result[0]->userMessage);
        self::assertSame('c', $result[1]->userMessage);
    }

    #[Test]
    public function last_n_with_fewer_than_n_returns_all(): void
    {
        $log = new ConversationLog($this->path);
        $log->append(self::exchange('only', 'one'));

        $result = $log->lastN(10);

        self::assertCount(1, $result);
        self::assertSame('only', $result[0]->userMessage);
    }

    #[Test]
    public function last_n_returns_empty_when_file_missing(): void
    {
        $log = new ConversationLog('/tmp/nonexistent-' . uniqid() . '.jsonl');

        self::assertSame([], $log->lastN(5));
    }

    #[Test]
    public function round_trip_preserves_tool_calls_and_thinking(): void
    {
        $log = new ConversationLog($this->path);
        $exchange = new Exchange(
            userMessage: 'inspect thermopylae',
            assistantResponse: 'The terrain is formidable.',
            summary: 'human: inspect...  →  The terrain...',
            toolCalls: [
                new ToolCallSummary(
                    toolName: 'inspect_terrain',
                    argumentsSummary: 'location: thermopylae',
                    status: 'ok',
                    resultContent: '{"elevation": "15m"}',
                    resultType: 'json',
                    expanded: true,
                ),
            ],
            thinkingContent: 'I should analyze the narrow pass.',
        );

        $log->append($exchange);
        $loaded = $log->readAt(0);

        self::assertNotNull($loaded);
        self::assertSame($exchange->id, $loaded->id);
        self::assertSame($exchange->userMessage, $loaded->userMessage);
        self::assertSame($exchange->thinkingContent, $loaded->thinkingContent);
        self::assertCount(1, $loaded->toolCalls);
        self::assertSame('inspect_terrain', $loaded->toolCalls[0]->toolName);
        self::assertSame('json', $loaded->toolCalls[0]->resultType);
        self::assertSame('{"elevation": "15m"}', $loaded->toolCalls[0]->resultContent);
    }

    #[Test]
    public function read_range_yields_correct_subset(): void
    {
        $log = new ConversationLog($this->path);
        $log->append(self::exchange('a', '1'));
        $log->append(self::exchange('b', '2'));
        $log->append(self::exchange('c', '3'));
        $log->append(self::exchange('d', '4'));

        $results = iterator_to_array($log->readRange(1, 2));

        self::assertCount(2, $results);
        self::assertSame('b', $results[0]->userMessage);
        self::assertSame('c', $results[1]->userMessage);
    }

    private static function exchange(string $user, string $assistant): Exchange
    {
        return new Exchange(
            userMessage: $user,
            assistantResponse: $assistant,
            summary: Exchange::summarize($user, $assistant),
        );
    }
}
