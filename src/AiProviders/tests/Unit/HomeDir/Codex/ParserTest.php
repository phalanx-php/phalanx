<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\HomeDir\Codex;

use Phalanx\AiProviders\Conversation\Options;
use Phalanx\AiProviders\Conversation\Record\Message;
use Phalanx\AiProviders\Conversation\Record\ToolCall;
use Phalanx\AiProviders\Conversation\Record\ToolResult;
use Phalanx\AiProviders\HomeDir\Codex\Parser;
use Phalanx\AiProviders\HomeDir\Codex\Source\All;
use Phalanx\AiProviders\HomeDir\Codex\Source\History;
use Phalanx\AiProviders\HomeDir\Codex\Source\Sessions;
use Phalanx\AiProviders\HomeDir\Codex\Source\Sqlite;
use Phalanx\Testing\UsesTempWorkspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins Codex Parser dispatch across Source subtypes.
 */
final class ParserTest extends TestCase
{
    use UsesTempWorkspace;

    #[Test]
    public function sessionsSourceProducesRecordsFromAllSessionFiles(): void
    {
        $parser = new Parser();
        $source = new Sessions(self::fixtureRoot() . '/sessions');
        $records = $parser->parse($source, Options::lenient())->toArray();

        // abc.jsonl has 3 lines; def.jsonl has 3 lines → 6 records total
        self::assertCount(6, $records);
    }

    #[Test]
    public function sessionsSourceProducesMessageRecords(): void
    {
        $parser = new Parser();
        $source = new Sessions(self::fixtureRoot() . '/sessions');
        $records = $parser->parse($source, Options::lenient())->toArray();

        $messages = array_filter($records, static fn ($r): bool => $r instanceof Message);
        self::assertNotEmpty($messages);
    }

    #[Test]
    public function sessionsSourceProducesToolCallRecord(): void
    {
        $parser = new Parser();
        $source = new Sessions(self::fixtureRoot() . '/sessions');
        $records = $parser->parse($source, Options::lenient())->toArray();

        $toolCalls = array_filter($records, static fn ($r): bool => $r instanceof ToolCall);
        self::assertNotEmpty($toolCalls);

        $tc = array_values($toolCalls)[0];
        self::assertInstanceOf(ToolCall::class, $tc);
        self::assertSame('lookup_history', $tc->toolName);
    }

    #[Test]
    public function historySourceProducesFourRecords(): void
    {
        $parser = new Parser();
        $source = new History(self::fixtureRoot() . '/history.jsonl');
        $records = $parser->parse($source, Options::lenient())->toArray();

        self::assertCount(4, $records);
    }

    #[Test]
    public function allSourceMergesAndDeduplicates(): void
    {
        $parser = new Parser();
        $source = new All(
            sessions: new Sessions(self::fixtureRoot() . '/sessions'),
            history: new History(self::fixtureRoot() . '/history.jsonl'),
            sqlite: null,
        );

        $records = $parser->parse($source, Options::lenient())->toArray();

        // Dedup is by canonical payload JSON (role+text+attachments for Message,
        // toolName+arguments for ToolCall, callId+output+isError for ToolResult).
        //
        // Sessions (6 records — abc.jsonl x3, def.jsonl x3):
        //   1. Message(system, "You are advising Pericles...")
        //   2. Message(user, "How should we fund the Parthenon...")
        //   3. Message(assistant, "Draw from the Delian League...")
        //   4. Message(user, "Socrates, what is the purpose of the agora?")
        //   5. ToolCall(lookup_history, {term:agora,polis:athens})
        //   6. ToolResult(call_01soc, "300 Spartans hold the pass")
        //
        // History (4 records — history.jsonl):
        //   1. Message(system, "You are advising Pericles...") ← DUP of sessions#1
        //   2. Message(user, "How should we fund...") ← DUP of sessions#2
        //   3. Message(user, "Socrates, what is the purpose...") ← DUP of sessions#4
        //   4. Message(assistant, "The agora is the civic heart...") ← NEW
        //
        // 6 sessions + 4 history - 3 exact payload duplicates = 7 unique records.
        self::assertCount(7, $records);
    }

    #[Test]
    public function allSourceAvailableSourcesReflectsConfiguredSources(): void
    {
        // configuredSources is determined at construction — no parse needed.
        $source = new All(
            sessions: new Sessions(self::fixtureRoot() . '/sessions'),
            history: new History(self::fixtureRoot() . '/history.jsonl'),
            sqlite: null,
        );

        $available = $source->configuredSources();
        self::assertContains('sessions', $available);
        self::assertContains('history', $available);
        self::assertNotContains('sqlite', $available);
    }

    #[Test]
    public function parserThrowsForUnknownSourceType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $parser = new Parser();
        $parser->parse(new \Phalanx\AiProviders\HomeDir\ClaudeCode\Source('/path'));
    }

    #[Test]
    public function nonExistentSessionsDirProducesNoRecords(): void
    {
        $parser = new Parser();
        $source = new Sessions('/does/not/exist');
        $records = $parser->parse($source, Options::lenient())->toArray();

        self::assertCount(0, $records);
    }

    #[Test]
    public function parseIsLazyAndDoesNotThrowBeforeIteration(): void
    {
        // parse() must return a Log without touching the filesystem.
        $parser = new Parser();
        $log = $parser->parse(new Sessions('/nonexistent/sessions/dir'), Options::lenient());

        self::assertInstanceOf(\Phalanx\AiProviders\Conversation\Log::class, $log);

        $records = $log->toArray();
        self::assertSame([], $records);
    }

    #[Test]
    public function toolCallHasCorrectArguments(): void
    {
        $parser = new Parser();
        $source = new Sessions(self::fixtureRoot() . '/sessions');
        $records = $parser->parse($source, Options::lenient())->toArray();

        $toolCalls = array_values(array_filter($records, static fn ($r): bool => $r instanceof ToolCall));
        $tc = $toolCalls[0];

        self::assertInstanceOf(ToolCall::class, $tc);
        // content in fixture: {"term":"agora","polis":"athens"}
        self::assertArrayHasKey('term', $tc->arguments);
        self::assertSame('agora', $tc->arguments['term']);
    }

    #[Test]
    public function emptyAllSourceProducesNoRecords(): void
    {
        $parser = new Parser();
        $source = new All(sessions: null, history: null, sqlite: null);
        $records = $parser->parse($source, Options::lenient())->toArray();

        self::assertCount(0, $records);
    }

    #[Test]
    public function timestampTieBreaksInFavourOfEarlierDeclaredSource(): void
    {
        // Two JSONL sources with the same timestamp. The sessions source is
        // declared first in All(); the merge must yield its record before the
        // history record when timestamps are equal.
        $workspace = $this->tempWorkspace('ai-providers-codex-');
        $sessionsDir = $workspace->dir('sessions');
        $workspace->file(
            'sessions/tie.jsonl',
            '{"type":"message","role":"user","content":"Leonidas at Thermopylae","ts":100,"raw_hash":"tie_a"}' . "\n",
        );

        $historyRow = '{"type":"message","role":"user","content":"Ephialtes betrays the pass",'
            . '"ts":100,"raw_hash":"tie_b"}';
        $historyFile = $workspace->file('history.jsonl', $historyRow . "\n");

        $parser = new Parser();
        $source = new All(
            sessions: new Sessions($sessionsDir),
            history: new History($historyFile),
            sqlite: null,
        );
        $records = $parser->parse($source, Options::lenient())->toArray();

        // Both records have distinct payloads so neither is deduped — we get 2.
        self::assertCount(2, $records);

        // The sessions source is declared first in All(); on a timestamp tie
        // it must win (strict < means earlier-declared source yields first).
        $first = $records[0];
        self::assertInstanceOf(Message::class, $first);
        self::assertSame('Leonidas at Thermopylae', $first->text);
    }

    #[Test]
    public function sqliteUnavailableAtRuntimeDoesNotPropagateToCallerAndYieldsRemainingRecords(): void
    {
        // Build an All with a real sessions + history source and a Sqlite source
        // pointing at a nonexistent file. The SqliteReader throws SqliteUnavailable
        // (or yields nothing) — either way, the Parser must not propagate the
        // error and the caller receives sessions+history records normally.
        //
        // Note: when ext-sqlite3 IS installed, the Sqlite source simply opens a
        // non-existent file and returns 0 rows (no exception path). When ext-sqlite3
        // is NOT installed, SqliteReader throws SqliteUnavailable, which parseAll()
        // catches and silently swallows. Both paths produce the same observable
        // result: the merge succeeds with sessions+history records.
        $parser = new Parser();
        $source = new All(
            sessions: new Sessions(self::fixtureRoot() . '/sessions'),
            history: new History(self::fixtureRoot() . '/history.jsonl'),
            sqlite: new Sqlite('/does/not/exist.sqlite'),
        );

        // Must not throw.
        $records = $parser->parse($source, Options::lenient())->toArray();

        // At minimum sessions (6) + history (4) minus dedup (3) = 7 records.
        self::assertGreaterThanOrEqual(7, count($records));

        // configuredSources reports sqlite as CONFIGURED even though it was
        // either absent or threw at runtime.
        self::assertContains('sqlite', $source->configuredSources());
    }

    #[Test]
    public function sessionsSourceProducesToolResultRecord(): void
    {
        $parser = new Parser();
        $source = new Sessions(self::fixtureRoot() . '/sessions');
        $records = $parser->parse($source, Options::lenient())->toArray();

        $toolResults = array_filter($records, static fn ($r): bool => $r instanceof ToolResult);
        self::assertNotEmpty($toolResults);

        $tr = array_values($toolResults)[0];
        self::assertInstanceOf(ToolResult::class, $tr);
        self::assertSame('call_01soc', $tr->callId);
        self::assertFalse($tr->isError);
    }

    private static function fixtureRoot(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/HomeDir/Codex';
    }
}
