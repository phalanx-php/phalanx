<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\Anthropic;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Cue\Effect\ArgumentsDelta;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Invocation\Completed;
use Phalanx\Panoply\Cue\Invocation\Failed;
use Phalanx\Panoply\Cue\Invocation\Started;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\Provider\Resolved;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Cue\Usage\Delta as UsageDelta;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Anthropic\CueMapper;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Sse\Event;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CueMapperTest extends TestCase
{
    #[Test]
    public function messageStartYieldsResolvedThenStarted(): void
    {
        $mapper = self::fixture();
        $event  = new Event('message_start', [
            'type'    => 'message_start',
            'message' => ['model' => 'claude-opus-4-7'],
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(2, $cues);
        self::assertInstanceOf(Resolved::class, $cues[0]);
        self::assertInstanceOf(Started::class, $cues[1]);
    }

    #[Test]
    public function resolvedCueCarriesProviderAndModel(): void
    {
        $mapper = self::fixture();
        $event  = new Event('message_start', [
            'message' => ['model' => 'claude-opus-4-7'],
        ]);

        $cues    = iterator_to_array($mapper->translate($event), preserve_keys: false);
        $resolved = $cues[0];

        self::assertInstanceOf(Resolved::class, $resolved);
        self::assertSame('anthropic', $resolved->provider);
        self::assertSame('claude-opus-4-7', $resolved->model);
    }

    #[Test]
    public function textDeltaYieldsTokenDeltaOnMessageChannel(): void
    {
        $mapper = self::fixture();

        // Prime the block state.
        iterator_to_array($mapper->translate(new Event('content_block_start', [
            'index'         => 0,
            'content_block' => ['type' => 'text'],
        ])), preserve_keys: false);

        $event = new Event('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => 'Hold the pass at Thermopylae.'],
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(1, $cues);
        self::assertInstanceOf(TokenDelta::class, $cues[0]);
        self::assertSame('Hold the pass at Thermopylae.', $cues[0]->text);
        self::assertSame(Channel::Message, $cues[0]->channel);
    }

    #[Test]
    public function thinkingDeltaYieldsTokenDeltaOnThinkingChannel(): void
    {
        $mapper = self::fixture();

        iterator_to_array($mapper->translate(new Event('content_block_start', [
            'index'         => 0,
            'content_block' => ['type' => 'thinking'],
        ])), preserve_keys: false);

        $event = new Event('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'Apollo guides our strategy.'],
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(1, $cues);
        self::assertInstanceOf(TokenDelta::class, $cues[0]);
        self::assertSame(Channel::Thinking, $cues[0]->channel);
        self::assertSame('Apollo guides our strategy.', $cues[0]->text);
    }

    #[Test]
    public function toolUseBlockStartYieldsEffectRequested(): void
    {
        $mapper = self::fixture();
        $event  = new Event('content_block_start', [
            'index'         => 0,
            'content_block' => ['type' => 'tool_use', 'id' => 'toolu_agora', 'name' => 'search_records'],
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(1, $cues);
        self::assertInstanceOf(Requested::class, $cues[0]);
        self::assertSame('toolu_agora', $cues[0]->effectId);
    }

    #[Test]
    public function inputJsonDeltaYieldsArgumentsDelta(): void
    {
        $mapper = self::fixture();

        // Open a tool_use block first.
        iterator_to_array($mapper->translate(new Event('content_block_start', [
            'index'         => 0,
            'content_block' => ['type' => 'tool_use', 'id' => 'toolu_sparta', 'name' => 'query'],
        ])), preserve_keys: false);

        $event = new Event('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"q":'],
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(1, $cues);
        self::assertInstanceOf(ArgumentsDelta::class, $cues[0]);
        self::assertSame('toolu_sparta', $cues[0]->effectId);
        self::assertSame('{"q":', $cues[0]->jsonDelta);
    }

    #[Test]
    public function contentBlockStopYieldsNoCues(): void
    {
        $mapper = self::fixture();
        $event  = new Event('content_block_stop', ['index' => 0]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(0, $cues);
    }

    #[Test]
    public function messageDeltaWithStopReasonYieldsTokenStop(): void
    {
        $mapper = self::fixture();
        $event  = new Event('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => 42],
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));
        self::assertCount(1, $stops);
        self::assertSame(StopReason::EndOfTurn, $stops[0]->reason);
    }

    #[Test]
    public function messageDeltaWithUsageYieldsUsageDelta(): void
    {
        $mapper = self::fixture();
        $event  = new Event('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => 17],
        ]);

        $cues   = iterator_to_array($mapper->translate($event), preserve_keys: false);
        $usages = array_values(array_filter($cues, static fn ($c) => $c instanceof UsageDelta));

        self::assertCount(1, $usages);
        self::assertSame(17, $usages[0]->outputTokens);
    }

    #[Test]
    public function messageStopYieldsFinalUsageThenInvocationCompleted(): void
    {
        $mapper = self::fixture();
        $event  = new Event('message_stop', ['type' => 'message_stop']);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(2, $cues);
        self::assertInstanceOf(FinalUsage::class, $cues[0]);
        self::assertInstanceOf(Completed::class, $cues[1]);
    }

    #[Test]
    public function messageStopFinalUsageCarriesCumulativeTokenCounts(): void
    {
        $mapper = self::fixture();

        // Simulate a message_start with input tokens.
        iterator_to_array($mapper->translate(new Event('message_start', [
            'message' => [
                'model' => 'claude-opus-4-7',
                'usage' => ['input_tokens' => 150],
            ],
        ])), preserve_keys: false);

        // Simulate a message_delta with output tokens.
        iterator_to_array($mapper->translate(new Event('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => 75],
        ])), preserve_keys: false);

        $cues = iterator_to_array($mapper->translate(new Event('message_stop', [])), preserve_keys: false);

        $finalUsage = array_values(array_filter($cues, static fn ($c) => $c instanceof FinalUsage));
        self::assertCount(1, $finalUsage);
        self::assertSame(150, $finalUsage[0]->inputTokens);
        self::assertSame(75, $finalUsage[0]->outputTokens);
    }

    #[Test]
    public function errorEventYieldsInvocationFailed(): void
    {
        $mapper = self::fixture();
        $event  = new Event('error', [
            'type'  => 'error',
            'error' => ['type' => 'overloaded_error', 'message' => 'Olympus is under siege.'],
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(1, $cues);
        self::assertInstanceOf(Failed::class, $cues[0]);
        self::assertSame('Olympus is under siege.', $cues[0]->reason);
    }

    #[Test]
    public function sequenceNumbersIncrementAcrossEvents(): void
    {
        $mapper = self::fixture();

        $cues1 = iterator_to_array($mapper->translate(new Event('message_start', [
            'message' => ['model' => 'claude-opus-4-7'],
        ])), preserve_keys: false);

        $cues2 = iterator_to_array($mapper->translate(new Event('message_stop', [])), preserve_keys: false);

        // Resolved=0, Started=1, FinalUsage=2, Completed=3
        self::assertSame(0, $cues1[0]->sequence);
        self::assertSame(1, $cues1[1]->sequence);
        self::assertSame(2, $cues2[0]->sequence);
        self::assertSame(3, $cues2[1]->sequence);
    }

    #[Test]
    public function unknownEventTypeYieldsNoCues(): void
    {
        $mapper = self::fixture();
        $event  = new Event('ping', ['type' => 'ping']);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(0, $cues);
    }

    #[Test]
    public function ignoredEventsAloneDoNotSynthesizeCompleted(): void
    {
        // Feeding only unknown event types must not flip $started — complete()
        // must emit nothing when no message_start was seen.
        $mapper = self::fixture();

        $event = new Event('unknown_anthropic_event', ['type' => 'unknown_anthropic_event']);
        iterator_to_array($mapper->translate($event), preserve_keys: false);

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

        // Feed only message_start — stream starts but message_stop never arrives.
        iterator_to_array($mapper->translate(new Event('message_start', [
            'message' => ['model' => 'claude-opus-4-7', 'usage' => ['input_tokens' => 10]],
        ])), preserve_keys: false);

        $cues = iterator_to_array($mapper->complete(), preserve_keys: false);

        self::assertCount(2, $cues);
        self::assertInstanceOf(FinalUsage::class, $cues[0]);
        self::assertInstanceOf(Completed::class, $cues[1]);
    }

    #[Test]
    public function completeAfterCleanShutdownYieldsNothing(): void
    {
        $mapper = self::fixture();

        // Feed a complete stream including the wire-native message_stop.
        iterator_to_array($mapper->translate(new Event('message_start', [
            'message' => ['model' => 'claude-opus-4-7', 'usage' => ['input_tokens' => 5]],
        ])), preserve_keys: false);

        iterator_to_array($mapper->translate(new Event('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => 3],
        ])), preserve_keys: false);

        iterator_to_array($mapper->translate(new Event('message_stop', [])), preserve_keys: false);

        // complete() must be a guarded no-op — message_stop already emitted the terminal cues.
        $cues = iterator_to_array($mapper->complete(), preserve_keys: false);

        self::assertCount(0, $cues);
    }

    private static function fixture(): CueMapper
    {
        return new CueMapper(self::invocation());
    }

    private static function invocation(): Invocation
    {
        return Invocation::of(
            id: 'inv_leonidas',
            agentId: 'leonidas',
            activityId: 'act_marathon',
            contextHash: str_repeat('a', 64),
            instructions: 'Hold the pass at Thermopylae.',
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::FileRead),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::ToolUse),
            transport: TransportNeeds::new()->streaming(),
        );
    }
}
