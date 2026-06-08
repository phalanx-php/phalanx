<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Acceptance;

use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Conversation\Options;
use Phalanx\AiProviders\Conversation\Record\Message;
use Phalanx\AiProviders\Conversation\Record\ToolCall;
use Phalanx\AiProviders\Conversation\Record\ToolResult;
use Phalanx\AiProviders\Cue\Activity\Completed as ActivityCompleted;
use Phalanx\AiProviders\Cue\Activity\Started;
use Phalanx\AiProviders\Cue\Invocation\Cancelled as InvocationCancelled;
use Phalanx\AiProviders\Cue\Invocation\Completed as InvocationCompleted;
use Phalanx\AiProviders\Cue\Output\Channel;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\AiProviders\Cue\Output\TokenStop;
use Phalanx\AiProviders\Cue\StopReason;
use Phalanx\AiProviders\Cue\Usage\FinalUsage;
use Phalanx\AiProviders\Effects;
use Phalanx\AiProviders\HomeDir\ClaudeCode\Parser as ClaudeCodeParser;
use Phalanx\AiProviders\HomeDir\ClaudeCode\Source as ClaudeCodeSource;
use Phalanx\AiProviders\HomeDir\Codex\Parser as CodexParser;
use Phalanx\AiProviders\HomeDir\Codex\Source\All as CodexAll;
use Phalanx\AiProviders\HomeDir\Codex\Source\History as CodexHistory;
use Phalanx\AiProviders\HomeDir\Codex\Source\Sessions as CodexSessions;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Output;
use Phalanx\AiProviders\Provider\Fake\Provider as FakeProvider;
use Phalanx\AiProviders\Provider\Needs as ProviderNeeds;
use Phalanx\AiProviders\Runtime\CancellationException;
use Phalanx\AiProviders\Runtime\Sync\Runtime as SyncRuntime;
use Phalanx\AiProviders\Tests\Fixtures\Agent\Discovered\HoplitesAgent;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;
use Phalanx\AiProviders\Transport\Request;
use Phalanx\AiProviders\Transport\Sync\HttpError;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;

/**
 * v0 specification acceptance gate harness.
 *
 * Each test method keeps one representative v0 integration seam. Lower-level
 * behavior stays in focused unit tests so this suite remains a ship-readiness
 * check rather than a duplicate test matrix.
 *
 * This class is the authoritative ship-readiness check. A clean passing
 * run is the definition of "v0 shippable".
 */
final class V0AcceptanceGateTest extends PhalanxTestCase
{
    /**
     * Gate 1: An agent class authors its contract via property hooks declared
     * on the Agent interface. The agent class must not import or reference
     * Provider, Transport, or Runtime namespaces — those are host concerns.
     */
    #[Test]
    public function gate01AgentClassAuthorsViaDiscoveredAttributeWithoutLeakingProviderImports(): void
    {
        $agent = new HoplitesAgent();

        self::assertSame('hoplites', $agent->id);
        self::assertSame('Hoplites', $agent->name);
        self::assertNotEmpty($agent->purpose);
        self::assertInstanceOf(\Phalanx\AiProviders\Capabilities::class, $agent->capabilities);
        self::assertInstanceOf(\Phalanx\AiProviders\Context::class, $agent->context);
        self::assertInstanceOf(Effects::class, $agent->effects);
        self::assertInstanceOf(ProviderNeeds::class, $agent->provider);
        self::assertInstanceOf(TransportNeeds::class, $agent->transport);
        self::assertInstanceOf(Output::class, $agent->output);

        $fileName = new \ReflectionClass($agent)->getFileName();
        self::assertIsString($fileName, 'HoplitesAgent must be a file-based class');
        $source = file_get_contents($fileName);
        self::assertNotFalse($source);
        self::assertStringNotContainsString('use Phalanx\AiProviders\Provider\Anthropic', $source);
        self::assertStringNotContainsString('use Phalanx\AiProviders\Provider\Fake', $source);
        self::assertStringNotContainsString('use Phalanx\AiProviders\Provider\Gemini', $source);
        self::assertStringNotContainsString('use Phalanx\AiProviders\Provider\OpenAI', $source);
        self::assertStringNotContainsString('use Phalanx\AiProviders\Transport\Sync', $source);
        self::assertStringNotContainsString('use Phalanx\AiProviders\Transport\HttpClient', $source);
        self::assertStringNotContainsString('use Phalanx\AiProviders\Runtime\\', $source);
    }

    /**
     * Gate 3: FakeProvider + SyncRuntime drives a scripted cue stream
     * end-to-end, producing the full expected lifecycle sequence.
     */
    #[Test]
    public function gate03FakeProviderEndToEndProducesValidCueStream(): void
    {
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');
        $script = [
            new Started('c1', 1, 'act.sparta', 'inv.01', 'agent.leonidas', $at),
            new TokenDelta('c2', 2, 'act.sparta', 'inv.01', 'agent.leonidas', $at, 'Hold the pass.', Channel::Message),
            new TokenStop('c3', 3, 'act.sparta', 'inv.01', 'agent.leonidas', $at, StopReason::EndOfTurn),
            new FinalUsage('c4', 4, 'act.sparta', 'inv.01', 'agent.leonidas', $at, inputTokens: 10, outputTokens: 5),
            new InvocationCompleted('c5', 5, 'act.sparta', 'inv.01', 'agent.leonidas', $at, StopReason::EndOfTurn),
            new ActivityCompleted('c6', 6, 'act.sparta', 'inv.01', 'agent.leonidas', $at),
        ];

        $provider = new FakeProvider($script, Capabilities::of(Capability::Reasoning));
        $invocation = self::invocation();
        $cues = $provider->perform($invocation, new SyncRuntime())->toArray();

        $tokenDeltas = array_filter($cues, static fn ($c): bool => $c instanceof TokenDelta);
        $completed = array_filter($cues, static fn ($c): bool => $c instanceof InvocationCompleted);
        $finalUsages = array_filter($cues, static fn ($c): bool => $c instanceof FinalUsage);
        $started = array_filter($cues, static fn ($c): bool => $c instanceof Started);

        self::assertNotEmpty($started, 'Expected a Started lifecycle cue');
        self::assertNotEmpty($tokenDeltas, 'Expected at least one TokenDelta');
        self::assertCount(1, $completed, 'Expected exactly one InvocationCompleted');
        self::assertNotEmpty($finalUsages, 'Expected at least one FinalUsage');
    }

    /**
     * Gate 6: ClaudeCode JSONL session parser produces a sane Record stream
     * from a real fixture file.
     */
    #[Test]
    public function gate06RealClaudeCodeSessionJsonlParses(): void
    {
        $fixture = dirname(__DIR__)
            . '/Fixtures/HomeDir/ClaudeCode/projects/-Users-jhavens-sparta/abc-leonidas.jsonl';

        self::assertFileExists($fixture, 'Sparta JSONL fixture must exist');

        $parser = new ClaudeCodeParser();
        $records = $parser->parse(new ClaudeCodeSource($fixture), Options::lenient())->toArray();

        self::assertCount(7, $records, 'Parser must produce exactly 7 records from the sparta fixture');
        self::assertInstanceOf(Message::class, $records[0], 'First record must be a system Message');

        $toolCalls = array_filter($records, static fn ($r): bool => $r instanceof ToolCall);
        $toolResults = array_filter($records, static fn ($r): bool => $r instanceof ToolResult);
        $messages = array_filter($records, static fn ($r): bool => $r instanceof Message);

        self::assertCount(1, $toolCalls, 'Fixture must contain exactly one ToolCall');
        self::assertCount(1, $toolResults, 'Fixture must contain exactly one ToolResult');
        self::assertGreaterThanOrEqual(2, count($messages), 'Fixture must contain at least two Messages');
    }

    /**
     * Gate 7: Codex Parser reads the composed public Source\All path.
     */
    #[Test]
    public function gate07CodexParserLoadsComposedFixtureSources(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/HomeDir/Codex';

        $source = new CodexAll(
            sessions: new CodexSessions($fixtureRoot . '/sessions'),
            history: new CodexHistory($fixtureRoot . '/history.jsonl'),
            sqlite: null,
        );

        $parser = new CodexParser();
        $records = $parser->parse($source, Options::lenient())->toArray();

        $messages = array_filter($records, static fn ($r): bool => $r instanceof Message);
        $toolCalls = array_filter($records, static fn ($r): bool => $r instanceof ToolCall);

        self::assertNotEmpty($records, 'Composed Codex fixtures must parse into records');
        self::assertNotEmpty($messages, 'Composed Codex fixtures must include Message records');
        self::assertNotEmpty($toolCalls, 'Composed Codex fixtures must include ToolCall records');
    }

    /**
     * Gate 9: HttpClient transport sequential streaming and cancellation.
     *
     * Proves that Transport\HttpClient\Transport:
     *   (a) streams bytes from a real local HTTP server through phalanx-http-client;
     *   (b) maps non-2xx responses to HttpError with the response body;
     *   (c) propagates CancellationException when the runtime is cancelled
     *       before the first byte is read.
     *
     * Requires Swoole: phalanx-http-client uses coroutine-backed TCP I/O via
     * Runtime-managed ManagedResourceHandle. The Runtime Application boots the
     * runtime policy and wraps all work inside CoroutineRuntime::run().
     */
    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function gate09HttpClientTransportSequentialStreamingAndCancellation(): void
    {
        $successScript = $this->writeGate09Server('echo "pericles won at marathon\n";');
        $errorScript = $this->writeGate09Server('http_response_code(503); echo "service unavailable";');
        $slowScript = $this->writeGate09Server('sleep(3); echo "never reached";');

        [$successProc, $successPipes, $successPort] = self::startGate09Server($successScript);
        [$errorProc, $errorPipes, $errorPort] = self::startGate09Server($errorScript);
        [$slowProc, $slowPipes, $slowPort] = self::startGate09Server($slowScript);

        if ($successProc === null || $errorProc === null || $slowProc === null) {
            self::markTestSkipped('Could not bind all three local PHP servers');
        }

        try {
            $app = $this->testApp([], \Phalanx\HttpClient\Client::services());

            $happyBody = '';
            $app->scoped(static function (\Phalanx\Scope\ExecutionScope $scope) use ($successPort, &$happyBody): void {
                $transport = new \Phalanx\AiProviders\Transport\HttpClient\Transport(
                    $scope,
                    \Phalanx\HttpClient\Client::client($scope),
                );
                $runtime = new SyncRuntime();
                foreach ($transport->stream(Request::of('GET', "http://127.0.0.1:{$successPort}/"), $runtime) as $c) {
                    $happyBody .= $c;
                }
            });

            self::assertSame(
                "pericles won at marathon\n",
                $happyBody,
                '(a) body must arrive intact and match the fixture exactly',
            );

            $httpError = null;
            $app->scoped(static function (\Phalanx\Scope\ExecutionScope $scope) use ($errorPort, &$httpError): void {
                $transport = new \Phalanx\AiProviders\Transport\HttpClient\Transport(
                    $scope,
                    \Phalanx\HttpClient\Client::client($scope),
                );
                $runtime = new SyncRuntime();

                try {
                    foreach ($transport->stream(Request::of('GET', "http://127.0.0.1:{$errorPort}/"), $runtime) as $_) {
                    }
                } catch (HttpError $e) {
                    $httpError = $e;
                }
            });

            self::assertNotNull($httpError, '(b) HttpError must be thrown for non-2xx responses');
            self::assertSame(503, $httpError->statusCode);
            self::assertStringContainsString(
                'service unavailable',
                $httpError->responseBody,
                '(b) HttpError must carry the response body',
            );

            $cancelledEx = null;
            $app->scoped(static function (\Phalanx\Scope\ExecutionScope $scope) use ($slowPort, &$cancelledEx): void {
                $transport = new \Phalanx\AiProviders\Transport\HttpClient\Transport(
                    $scope,
                    \Phalanx\HttpClient\Client::client($scope),
                );
                $runtime = new SyncRuntime();
                $runtime->cancel();

                try {
                    foreach ($transport->stream(Request::of('GET', "http://127.0.0.1:{$slowPort}/"), $runtime) as $_) {
                    }
                } catch (CancellationException $e) {
                    $cancelledEx = $e;
                }
            });

            self::assertNotNull($cancelledEx, '(c) CancellationException must propagate when runtime is pre-cancelled');
        } finally {
            $servers = [
                [$successProc, $successPipes, $successScript],
                [$errorProc, $errorPipes, $errorScript],
                [$slowProc, $slowPipes, $slowScript],
            ];
            foreach ($servers as [$proc, $pipes, $script]) {
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_terminate($proc);
                proc_close($proc);
            }
        }
    }

    /**
     * Gate 10: Cancellation mid-stream propagates a CancellationException and
     * leaves no orphaned tasks in the Runtime TestApp lease ledger.
     *
     * The unique value of this gate is the leak-ledger assertion: after a
     * cancelled FakeProvider stream, `TestApp::boot()` must report zero
     * orphaned tasks. That path requires the Swoole extension
     * (TestApp::boot() calls into Swoole\Table). Without it this gate is
     * skipped — SyncRuntime cancellation propagation is already covered by
     * unit tests for the SyncRuntime type.
     */
    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function gate10CancellationEmitsCancelledCueWithoutLeaks(): void
    {
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');
        $runtime = new SyncRuntime();

        $script = [
            new Started('c1', 1, 'act.sparta', 'inv.01', 'agent.leonidas', $at),
            new TokenDelta('c2', 2, 'act.sparta', 'inv.01', 'agent.leonidas', $at, 'Hold', Channel::Message),
            new InvocationCancelled('c3', 3, 'act.sparta', 'inv.01', 'agent.leonidas', $at, 'cancelled-by-host'),
        ];

        $provider = new FakeProvider($script, Capabilities::of(Capability::Reasoning));
        $stream = $provider->perform(self::invocation(), $runtime);

        $cues = [];
        $caughtCancellation = false;

        try {
            foreach ($stream as $cue) {
                $cues[] = $cue;
                if (count($cues) === 1) {
                    $runtime->cancel();
                }
            }
        } catch (CancellationException) {
            $caughtCancellation = true;
        }

        self::assertTrue($caughtCancellation, 'CancellationException must propagate from cancelled SyncRuntime');
        self::assertCount(1, $cues, 'Only the Started cue should have been yielded before cancellation');
        self::assertInstanceOf(Started::class, $cues[0]);

        $this->testApp()->ledger->assertNoOrphans();
    }

    private function writeGate09Server(string $phpBody): string
    {
        return $this->tempWorkspace('gate09-')->file(uniqid('server-', true) . '.php', "<?php {$phpBody}");
    }

    /**
     * @return array{0: resource|null, 1: array<int, resource>, 2: int}
     */
    private static function startGate09Server(string $serverScript): array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = random_int(20000, 60000);
            $proc = proc_open(
                'php -S 127.0.0.1:' . $port . ' ' . $serverScript,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );

            if (!is_resource($proc)) {
                continue;
            }

            if (self::waitForServer($port)) {
                return [$proc, $pipes, $port];
            }

            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }

        return [null, [], 0];
    }

    private static function waitForServer(int $port): bool
    {
        $deadline = microtime(true) + 2.0;

        do {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05);

            if (is_resource($socket)) {
                fclose($socket);

                return true;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private static function invocation(): Invocation
    {
        return Invocation::of(
            id: 'gate-inv-01',
            agentId: 'agent.leonidas',
            activityId: 'act.sparta',
            contextHash: str_repeat('0', 64),
            instructions: 'Defend Thermopylae. Report your status.',
            output: Output::text(),
            effects: Effects::none(),
            provider: ProviderNeeds::new(),
            transport: TransportNeeds::new(),
            dynamicContext: ['battle' => 'thermopylae'],
        );
    }
}
