<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\HomeDir\ClaudeCode;

use Phalanx\Panoply\Conversation\Options;
use Phalanx\Panoply\Conversation\Record\Message;
use Phalanx\Panoply\Conversation\Record\Metadata;
use Phalanx\Panoply\Conversation\Record\ToolCall;
use Phalanx\Panoply\Conversation\Record\ToolResult;
use Phalanx\Panoply\Conversation\Record\Unknown;
use Phalanx\Panoply\HomeDir\ClaudeCode\Parser;
use Phalanx\Panoply\HomeDir\ClaudeCode\Source;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the ClaudeCode Parser's mapping table and strict/lenient mode behavior.
 */
final class ParserTest extends TestCase
{
    #[Test]
    public function parsesSystemMessageFromFixture(): void
    {
        $parser  = new Parser();
        $records = $parser->parse(self::spartaFixture(), Options::lenient())->toArray();

        $systemMessages = array_filter(
            $records,
            static fn ($r): bool => $r instanceof Message && $r->role === 'system',
        );
        self::assertNotEmpty($systemMessages);
    }

    #[Test]
    public function parsesUserMessageFromFixture(): void
    {
        $parser  = new Parser();
        $records = $parser->parse(self::spartaFixture(), Options::lenient())->toArray();

        $userMessages = array_filter($records, static fn ($r): bool => $r instanceof Message && $r->role === 'user');
        self::assertNotEmpty($userMessages);
    }

    #[Test]
    public function parsesAssistantMessageFromFixture(): void
    {
        $parser  = new Parser();
        $records = $parser->parse(self::spartaFixture(), Options::lenient())->toArray();

        $assistantMessages = array_filter(
            $records,
            static fn ($r): bool => $r instanceof Message && $r->role === 'assistant',
        );
        self::assertNotEmpty($assistantMessages);
    }

    #[Test]
    public function parsesToolCallFromFixture(): void
    {
        $parser  = new Parser();
        $records = $parser->parse(self::spartaFixture(), Options::lenient())->toArray();

        $toolCalls = array_filter($records, static fn ($r): bool => $r instanceof ToolCall);
        self::assertNotEmpty($toolCalls);

        $tc = array_values($toolCalls)[0];
        self::assertInstanceOf(ToolCall::class, $tc);
        self::assertSame('count_warriors', $tc->toolName);
    }

    #[Test]
    public function toolCallPreservesArguments(): void
    {
        $parser  = new Parser();
        $records = $parser->parse(self::spartaFixture(), Options::lenient())->toArray();

        $toolCalls = array_values(array_filter($records, static fn ($r): bool => $r instanceof ToolCall));
        $tc        = $toolCalls[0];

        self::assertInstanceOf(ToolCall::class, $tc);
        self::assertArrayHasKey('formation', $tc->arguments);
        self::assertSame('phalanx', $tc->arguments['formation']);
    }

    #[Test]
    public function parsesToolResultFromFixture(): void
    {
        $parser  = new Parser();
        $records = $parser->parse(self::spartaFixture(), Options::lenient())->toArray();

        $toolResults = array_filter($records, static fn ($r): bool => $r instanceof ToolResult);
        self::assertNotEmpty($toolResults);

        $tr = array_values($toolResults)[0];
        self::assertInstanceOf(ToolResult::class, $tr);
        self::assertFalse($tr->isError);
    }

    #[Test]
    public function parsesSummaryAsMetadata(): void
    {
        $parser  = new Parser();
        $records = $parser->parse(self::spartaFixture(), Options::lenient())->toArray();

        $metaRecords = array_filter($records, static fn ($r): bool => $r instanceof Metadata);
        self::assertNotEmpty($metaRecords);

        $meta = array_values($metaRecords)[0];
        self::assertInstanceOf(Metadata::class, $meta);
        self::assertSame('summary', $meta->key);
    }

    #[Test]
    public function unknownTypeInLenientModeYieldsUnknownRecord(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'panoply_cc_') . '.jsonl';
        $line = '{"type":"unknown_future_type","timestamp":"2026-05-17T10:00:00.000Z","content":"data"}';
        file_put_contents($tmpFile, $line . "\n");

        try {
            $parser  = new Parser();
            $records = $parser->parse(new Source($tmpFile), Options::lenient())->toArray();

            $unknown = array_filter($records, static fn ($r): bool => $r instanceof Unknown);
            self::assertNotEmpty($unknown);

            $u = array_values($unknown)[0];
            self::assertInstanceOf(Unknown::class, $u);
            self::assertSame('unknown_future_type', $u->parserHint);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function unknownTypeInLoudModeThrows(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'panoply_cc_') . '.jsonl';
        file_put_contents($tmpFile, '{"type":"weird_type","timestamp":"2026-05-17T10:00:00.000Z"}' . "\n");

        try {
            $this->expectException(\UnexpectedValueException::class);

            $parser = new Parser();
            $parser->parse(new Source($tmpFile), Options::default())->toArray();
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function unknownTypeInSilentModeDropsRecord(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'panoply_cc_') . '.jsonl';
        file_put_contents($tmpFile, '{"type":"weird_type","timestamp":"2026-05-17T10:00:00.000Z"}' . "\n");

        try {
            $parser  = new Parser();
            $records = $parser->parse(new Source($tmpFile), Options::silent())->toArray();

            self::assertCount(0, $records);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function parserRejectsNonClaudeCodeSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $parser = new Parser();
        $parser->parse(new \Phalanx\Panoply\HomeDir\GeminiCli\Source('/some/path'));
    }

    #[Test]
    public function nonExistentFileReturnsEmptyLog(): void
    {
        $parser  = new Parser();
        $records = $parser->parse(new Source('/does/not/exist.jsonl'), Options::lenient())->toArray();

        self::assertCount(0, $records);
    }

    #[Test]
    public function parseIsLazyAndDoesNotThrowBeforeIteration(): void
    {
        // parse() must return a Log without touching the filesystem. Filesystem
        // access only happens when the returned Log is iterated.
        $parser = new Parser();
        $log    = $parser->parse(new Source('/nonexistent/path/that/does/not/exist.jsonl'), Options::lenient());

        self::assertInstanceOf(\Phalanx\Panoply\Conversation\Log::class, $log);

        // Terminal op: filesystem access happens now, not during parse().
        $records = $log->toArray();
        self::assertSame([], $records);
    }

    #[Test]
    public function marathonFixtureProducesTwoMessages(): void
    {
        $parser  = new Parser();
        $records = $parser->parse(self::marathonFixture(), Options::lenient())->toArray();

        $messages = array_filter($records, static fn ($r): bool => $r instanceof Message);
        self::assertCount(2, $messages);
    }

    private static function spartaFixture(): Source
    {
        return new Source(
            dirname(__DIR__, 3) . '/Fixtures/HomeDir/ClaudeCode/projects/-Users-jhavens-sparta/abc-leonidas.jsonl',
        );
    }

    private static function marathonFixture(): Source
    {
        $base = dirname(__DIR__, 3) . '/Fixtures/HomeDir/ClaudeCode/projects';

        return new Source($base . '/-Users-jhavens-marathon/def-pheidippides.jsonl');
    }
}
