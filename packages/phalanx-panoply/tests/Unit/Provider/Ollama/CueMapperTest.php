<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\Ollama;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Invocation\Completed;
use Phalanx\Panoply\Cue\Invocation\Failed;
use Phalanx\Panoply\Cue\Invocation\Started;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\Provider\Resolved;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Ollama\CueMapper;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CueMapperTest extends TestCase
{
    #[Test]
    public function firstLineWithRoleYieldsResolvedThenStarted(): void
    {
        $mapper = self::fixture();
        $line   = ['model' => 'llama3.1', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => false];

        $cues = iterator_to_array($mapper->translate($line), preserve_keys: false);

        self::assertCount(2, $cues);
        self::assertInstanceOf(Resolved::class, $cues[0]);
        self::assertInstanceOf(Started::class, $cues[1]);
    }

    #[Test]
    public function resolvedCueCarriesOllamaProvider(): void
    {
        $mapper = self::fixture();
        $line   = ['model' => 'llama3.1', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => false];

        $cues = iterator_to_array($mapper->translate($line), preserve_keys: false);

        self::assertSame('ollama', $cues[0]->provider);
        self::assertSame('llama3.1', $cues[0]->model);
    }

    #[Test]
    public function contentDeltaYieldsTokenDelta(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $line = ['message' => ['role' => 'assistant', 'content' => 'Leonidas rallied the hoplites.'], 'done' => false];

        $cues = iterator_to_array($mapper->translate($line), preserve_keys: false);

        self::assertCount(1, $cues);
        self::assertInstanceOf(TokenDelta::class, $cues[0]);
        self::assertSame('Leonidas rallied the hoplites.', $cues[0]->text);
    }

    #[Test]
    public function doneLineYieldsTokenStopThenFinalUsageThenCompleted(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $line = [
            'message'           => ['role' => 'assistant', 'content' => ''],
            'done'              => true,
            'prompt_eval_count' => 30,
            'eval_count'        => 15,
        ];

        $cues = iterator_to_array($mapper->translate($line), preserve_keys: false);

        // Filter out the potential empty content delta.
        $typed = array_values(array_filter($cues, static fn ($c) => !($c instanceof TokenDelta)));

        self::assertCount(3, $typed);
        self::assertInstanceOf(TokenStop::class, $typed[0]);
        self::assertInstanceOf(FinalUsage::class, $typed[1]);
        self::assertInstanceOf(Completed::class, $typed[2]);
        self::assertSame(30, $typed[1]->inputTokens);
        self::assertSame(15, $typed[1]->outputTokens);
    }

    #[Test]
    public function doneLineStopReasonIsEndOfTurn(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $line = ['message' => ['role' => 'assistant', 'content' => ''], 'done' => true];

        $cues  = iterator_to_array($mapper->translate($line), preserve_keys: false);
        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));

        self::assertSame(StopReason::EndOfTurn, $stops[0]->reason);
    }

    #[Test]
    public function doneLineWithToolCallsStopReasonIsToolUse(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $line = [
            'message' => [
                'role'       => 'assistant',
                'content'    => '',
                'tool_calls' => [
                    ['function' => ['name' => 'query_olympus', 'arguments' => ['topic' => 'Delphi']]],
                ],
            ],
            'done'              => true,
            'prompt_eval_count' => 25,
            'eval_count'        => 12,
        ];

        $cues  = iterator_to_array($mapper->translate($line), preserve_keys: false);
        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));

        self::assertCount(1, $stops);
        self::assertSame(StopReason::ToolUse, $stops[0]->reason);
    }

    #[Test]
    public function doneLineWithToolCallsYieldsEffectRequested(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $line = [
            'message' => [
                'role'       => 'assistant',
                'content'    => '',
                'tool_calls' => [
                    ['function' => ['name' => 'search_agora', 'arguments' => ['query' => 'Sparta']]],
                ],
            ],
            'done'              => true,
            'prompt_eval_count' => 20,
            'eval_count'        => 10,
        ];

        $cues      = iterator_to_array($mapper->translate($line), preserve_keys: false);
        $requested = array_values(array_filter($cues, static fn ($c) => $c instanceof Requested));

        self::assertCount(1, $requested);
        self::assertStringContainsString('search_agora', $requested[0]->summary);
    }

    #[Test]
    public function errorLineYieldsInvocationFailed(): void
    {
        $mapper = self::fixture();
        $line   = ['error' => "model 'olympus-7b' not found, try pulling it first"];

        $cues = iterator_to_array($mapper->translate($line), preserve_keys: false);

        self::assertCount(1, $cues);
        self::assertInstanceOf(Failed::class, $cues[0]);
        self::assertStringContainsString('olympus-7b', $cues[0]->reason);
    }

    #[Test]
    public function ignoredEventsAloneDoNotSynthesizeCompleted(): void
    {
        // Feeding NDJSON lines that don't set $started (no message.role key)
        // must not flip $started — complete() must emit nothing.
        $mapper = self::fixture();

        // A line without message.role does not start the stream.
        iterator_to_array($mapper->translate(['info' => 'model loaded']), preserve_keys: false);

        $cues = iterator_to_array($mapper->complete(), preserve_keys: false);

        self::assertCount(0, $cues);
    }

    // ── complete() defensive terminator ──────────────────────────────────────

    #[Test]
    public function completeOnUnstartedStreamYieldsNoCues(): void
    {
        $mapper = self::fixture();

        $cues = iterator_to_array($mapper->complete(), preserve_keys: false);

        self::assertCount(0, $cues);
    }

    #[Test]
    public function completeAfterStartedWithoutWireTerminatorEmitsFinalUsageAndCompleted(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        // Feed a content delta — stream has started but done:true never arrives.
        iterator_to_array($mapper->translate([
            'message' => ['role' => 'assistant', 'content' => 'Leonidas '],
            'done'    => false,
        ]), preserve_keys: false);

        $cues = iterator_to_array($mapper->complete(), preserve_keys: false);

        self::assertCount(3, $cues);
        self::assertInstanceOf(TokenStop::class, $cues[0]);
        self::assertInstanceOf(FinalUsage::class, $cues[1]);
        self::assertInstanceOf(Completed::class, $cues[2]);
    }

    #[Test]
    public function completeAfterCleanShutdownYieldsNothing(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        // Feed a done line — wire-native terminator already emitted the terminal cues.
        iterator_to_array($mapper->translate([
            'message'           => ['role' => 'assistant', 'content' => ''],
            'done'              => true,
            'prompt_eval_count' => 5,
            'eval_count'        => 3,
        ]), preserve_keys: false);

        // complete() must be a guarded no-op.
        $cues = iterator_to_array($mapper->complete(), preserve_keys: false);

        self::assertCount(0, $cues);
    }

    #[Test]
    public function sequenceNumbersIncrementAcrossLines(): void
    {
        $mapper = self::fixture();

        $cues1 = iterator_to_array($mapper->translate([
            'model' => 'llama3.1', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => false,
        ]), preserve_keys: false);

        $cues2 = iterator_to_array($mapper->translate([
            'message' => ['role' => 'assistant', 'content' => ''],
            'done'    => true,
            'eval_count' => 5, 'prompt_eval_count' => 10,
        ]), preserve_keys: false);

        // Resolved=0, Started=1
        self::assertSame(0, $cues1[0]->sequence);
        self::assertSame(1, $cues1[1]->sequence);
        // TokenStop=2, FinalUsage=3, Completed=4
        self::assertSame(2, $cues2[0]->sequence);
    }

    private static function fixture(): CueMapper
    {
        return new CueMapper(self::invocation());
    }

    private static function primeStart(CueMapper $mapper): void
    {
        iterator_to_array($mapper->translate([
            'model' => 'llama3.1', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => false,
        ]), preserve_keys: false);
    }

    private static function invocation(): Invocation
    {
        return Invocation::of(
            id: 'inv_achilles',
            agentId: 'achilles',
            activityId: 'act_sparta',
            contextHash: str_repeat('h', 64),
            instructions: 'Lead the hoplites.',
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::FileRead),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::ToolUse),
            transport: TransportNeeds::new()->streaming(),
        );
    }
}
