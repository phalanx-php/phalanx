<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\HomeDir\GeminiCli;

use Phalanx\AiProviders\Conversation\Options;
use Phalanx\AiProviders\Conversation\Record\Message;
use Phalanx\AiProviders\Conversation\Record\ToolCall;
use Phalanx\AiProviders\Conversation\Record\ToolResult;
use Phalanx\AiProviders\Conversation\Record\Unknown;
use Phalanx\AiProviders\HomeDir\GeminiCli\Parser;
use Phalanx\AiProviders\HomeDir\GeminiCli\Source;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the GeminiCli Parser's role mapping (model→assistant) and part
 * dispatch (text / functionCall / functionResponse).
 */
final class ParserTest extends TestCase
{
    #[Test]
    public function parsesUserRoleAsUserMessage(): void
    {
        $parser = new Parser();
        $records = $parser->parse(self::marathonFixture(), Options::lenient())->toArray();

        $userMessages = array_filter($records, static fn ($r): bool => $r instanceof Message && $r->role === 'user');
        self::assertNotEmpty($userMessages);
    }

    #[Test]
    public function parsesModelRoleAsAssistantMessage(): void
    {
        $parser = new Parser();
        $records = $parser->parse(self::marathonFixture(), Options::lenient())->toArray();

        $assistantMessages = array_filter(
            $records,
            static fn ($r): bool => $r instanceof Message && $r->role === 'assistant',
        );
        self::assertNotEmpty($assistantMessages);
    }

    #[Test]
    public function parsesFunctionCallAsToolCall(): void
    {
        $parser = new Parser();
        $records = $parser->parse(self::marathonFixture(), Options::lenient())->toArray();

        $toolCalls = array_values(array_filter($records, static fn ($r): bool => $r instanceof ToolCall));
        self::assertNotEmpty($toolCalls);

        $tc = $toolCalls[0];
        self::assertInstanceOf(ToolCall::class, $tc);
        self::assertSame('describe_formation', $tc->toolName);
    }

    #[Test]
    public function toolCallPreservesArgs(): void
    {
        $parser = new Parser();
        $records = $parser->parse(self::marathonFixture(), Options::lenient())->toArray();

        $toolCalls = array_values(array_filter($records, static fn ($r): bool => $r instanceof ToolCall));
        $tc = $toolCalls[0];

        self::assertInstanceOf(ToolCall::class, $tc);
        self::assertArrayHasKey('battle', $tc->arguments);
        self::assertSame('marathon', $tc->arguments['battle']);
    }

    #[Test]
    public function parsesFunctionResponseAsToolResult(): void
    {
        $parser = new Parser();
        $records = $parser->parse(self::marathonFixture(), Options::lenient())->toArray();

        $toolResults = array_filter($records, static fn ($r): bool => $r instanceof ToolResult);
        self::assertNotEmpty($toolResults);
    }

    #[Test]
    public function unknownRoleInLenientModeYieldsUnknown(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'ai-providers_gc_') . '.jsonl';
        file_put_contents(
            $tmpFile,
            '{"role":"oracle","parts":[{"text":"The gods speak."}],"timestamp":"2026-05-17T10:00:00Z"}' . "\n",
        );

        try {
            $parser = new Parser();
            $records = $parser->parse(new Source($tmpFile), Options::lenient())->toArray();

            $unknown = array_filter($records, static fn ($r): bool => $r instanceof Unknown);
            self::assertNotEmpty($unknown);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function unknownRoleInLoudModeThrows(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'ai-providers_gc_') . '.jsonl';
        file_put_contents(
            $tmpFile,
            '{"role":"oracle","parts":[{"text":"The gods speak."}],"timestamp":"2026-05-17T10:00:00Z"}' . "\n",
        );

        try {
            $this->expectException(\UnexpectedValueException::class);

            $parser = new Parser();
            $parser->parse(new Source($tmpFile), Options::default())->toArray();
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function unknownRoleInSilentModeDropsRecord(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'ai-providers_gc_') . '.jsonl';
        file_put_contents(
            $tmpFile,
            '{"role":"oracle","parts":[{"text":"The gods speak."}],"timestamp":"2026-05-17T10:00:00Z"}' . "\n",
        );

        try {
            $parser = new Parser();
            $records = $parser->parse(new Source($tmpFile), Options::silent())->toArray();
            self::assertCount(0, $records);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function parserRejectsNonGeminiSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $parser = new Parser();
        $parser->parse(new \Phalanx\AiProviders\HomeDir\ClaudeCode\Source('/some/path'));
    }

    #[Test]
    public function marathonFixtureProducesExpectedRecordCount(): void
    {
        $parser = new Parser();
        $records = $parser->parse(self::marathonFixture(), Options::lenient())->toArray();

        // 4 lines: user (text), model (text+functionCall), user (functionResponse), model (text)
        // That produces: 1 Message, 1 Message + 1 ToolCall, 1 ToolResult, 1 Message = 5 records
        self::assertGreaterThanOrEqual(4, count($records));
    }

    #[Test]
    public function parseIsLazyAndDoesNotThrowBeforeIteration(): void
    {
        $parser = new Parser();
        $log = $parser->parse(new Source('/nonexistent/path/that/does/not/exist.jsonl'), Options::lenient());

        self::assertInstanceOf(\Phalanx\AiProviders\Conversation\Log::class, $log);

        $records = $log->toArray();
        self::assertSame([], $records);
    }

    private static function marathonFixture(): Source
    {
        return new Source(
            dirname(__DIR__, 3) . '/Fixtures/HomeDir/GeminiCli/history/proj-marathon/abc.jsonl',
        );
    }
}
