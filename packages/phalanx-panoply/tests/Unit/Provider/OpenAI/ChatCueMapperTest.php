<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\OpenAI;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Cue\Effect\ArgumentsDelta;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Invocation\Completed;
use Phalanx\Panoply\Cue\Invocation\Started;
use Phalanx\Panoply\Cue\Output\Channel;
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
use Phalanx\Panoply\Provider\OpenAI\ChatCueMapper;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Sse\Event;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChatCueMapperTest extends TestCase
{
    #[Test]
    public function firstChunkWithRoleYieldsResolvedThenStarted(): void
    {
        $mapper = self::fixture();
        $event  = new Event('', [
            'id'      => 'chatcmpl-x',
            'model'   => 'gpt-5',
            'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => null]],
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
        $event  = new Event('', [
            'model'   => 'gpt-5',
            'choices' => [['index' => 0, 'delta' => ['role' => 'assistant'], 'finish_reason' => null]],
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertInstanceOf(Resolved::class, $cues[0]);
        self::assertSame('openai', $cues[0]->provider);
        self::assertSame('gpt-5', $cues[0]->model);
    }

    #[Test]
    public function contentDeltaYieldsTokenDeltaOnMessageChannel(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $event = new Event('', [
            'choices' => [[
                'index'         => 0,
                'delta'         => ['content' => 'Hold the pass at Thermopylae.'],
                'finish_reason' => null,
            ]],
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(1, $cues);
        self::assertInstanceOf(TokenDelta::class, $cues[0]);
        self::assertSame('Hold the pass at Thermopylae.', $cues[0]->text);
        self::assertSame(Channel::Message, $cues[0]->channel);
    }

    #[Test]
    public function reasoningContentDeltaYieldsTokenDeltaOnReasoningChannel(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $event = new Event('', [
            'choices' => [[
                'index'         => 0,
                'delta'         => ['reasoning_content' => 'Apollo deliberates the strategy.'],
                'finish_reason' => null,
            ]],
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(1, $cues);
        self::assertInstanceOf(TokenDelta::class, $cues[0]);
        self::assertSame(Channel::Reasoning, $cues[0]->channel);
        self::assertSame('Apollo deliberates the strategy.', $cues[0]->text);
    }

    #[Test]
    public function finishReasonStopYieldsTokenStop(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $event = new Event('', [
            'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']],
        ]);

        $cues  = iterator_to_array($mapper->translate($event), preserve_keys: false);
        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));

        self::assertCount(1, $stops);
        self::assertSame(StopReason::EndOfTurn, $stops[0]->reason);
    }

    #[Test]
    public function finishReasonLengthMapsToMaxTokens(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $event = new Event('', [
            'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'length']],
        ]);

        $cues  = iterator_to_array($mapper->translate($event), preserve_keys: false);
        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));

        self::assertSame(StopReason::MaxTokens, $stops[0]->reason);
    }

    #[Test]
    public function finishReasonToolCallsMapsToToolUse(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $event = new Event('', [
            'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']],
        ]);

        $cues  = iterator_to_array($mapper->translate($event), preserve_keys: false);
        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));

        self::assertSame(StopReason::ToolUse, $stops[0]->reason);
    }

    #[Test]
    public function toolCallFirstChunkYieldsEffectRequested(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $event = new Event('', [
            'choices' => [[
                'index'         => 0,
                'delta'         => ['tool_calls' => [[
                    'index'    => 0,
                    'id'       => 'call_olympus',
                    'type'     => 'function',
                    'function' => ['name' => 'search_agora', 'arguments' => ''],
                ]]],
                'finish_reason' => null,
            ]],
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        $requested = array_values(array_filter($cues, static fn ($c) => $c instanceof Requested));
        self::assertCount(1, $requested);
        self::assertSame('tc_call_olympus', $requested[0]->effectId);
        self::assertStringContainsString('search_agora', $requested[0]->summary);
    }

    #[Test]
    public function toolCallSubsequentChunkYieldsArgumentsDelta(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        // First chunk — registers effectId.
        $first = new Event('', [
            'choices' => [[
                'index'         => 0,
                'delta'         => ['tool_calls' => [[
                    'index'    => 0,
                    'id'       => 'call_sparta',
                    'type'     => 'function',
                    'function' => ['name' => 'rally', 'arguments' => ''],
                ]]],
                'finish_reason' => null,
            ]],
        ]);
        iterator_to_array($mapper->translate($first), preserve_keys: false);

        // Second chunk — argument delta.
        $second = new Event('', [
            'choices' => [[
                'index'         => 0,
                'delta'         => ['tool_calls' => [
                    ['index' => 0, 'function' => ['arguments' => '{"count":300}']],
                ]],
                'finish_reason' => null,
            ]],
        ]);

        $cues   = iterator_to_array($mapper->translate($second), preserve_keys: false);
        $deltas = array_values(array_filter($cues, static fn ($c) => $c instanceof ArgumentsDelta));

        self::assertCount(1, $deltas);
        self::assertSame('tc_call_sparta', $deltas[0]->effectId);
        self::assertSame('{"count":300}', $deltas[0]->jsonDelta);
    }

    #[Test]
    public function parallelToolCallsTwoIndicesTrackedIndependently(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $event = new Event('', [
            'choices' => [[
                'index'         => 0,
                'delta'         => ['tool_calls' => [
                    ['index' => 0, 'id' => 'call_apollo', 'type' => 'function',
                        'function' => ['name' => 'query_olympus', 'arguments' => '']],
                    ['index' => 1, 'id' => 'call_leonidas', 'type' => 'function',
                        'function' => ['name' => 'rally_hoplites', 'arguments' => '']],
                ]],
                'finish_reason' => null,
            ]],
        ]);

        $cues      = iterator_to_array($mapper->translate($event), preserve_keys: false);
        $requested = array_values(array_filter($cues, static fn ($c) => $c instanceof Requested));

        self::assertCount(2, $requested);
        self::assertSame('tc_call_apollo', $requested[0]->effectId);
        self::assertSame('tc_call_leonidas', $requested[1]->effectId);
    }

    #[Test]
    public function finishReasonContentFilterMapsToError(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $event = new Event('', [
            'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'content_filter']],
        ]);

        $cues  = iterator_to_array($mapper->translate($event), preserve_keys: false);
        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));

        self::assertCount(1, $stops);
        self::assertSame(StopReason::Error, $stops[0]->reason);
    }

    #[Test]
    public function finishReasonUnknownStringMapsToEndOfTurn(): void
    {
        // Unknown finish_reason strings fall through to the default StopReason::EndOfTurn.
        $mapper = self::fixture();
        self::primeStart($mapper);

        $event = new Event('', [
            'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'function_call']],
        ]);

        $cues  = iterator_to_array($mapper->translate($event), preserve_keys: false);
        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));

        self::assertCount(1, $stops);
        self::assertSame(StopReason::EndOfTurn, $stops[0]->reason);
    }

    #[Test]
    public function emptyStreamYieldsNoCues(): void
    {
        // A mapper that receives no translate() calls should emit nothing from complete().
        $mapper = self::fixture();

        $cues = iterator_to_array($mapper->complete(), preserve_keys: false);

        self::assertCount(0, $cues);
    }

    #[Test]
    public function streamEndingMidContentEmitsCompletedOnce(): void
    {
        // A stream that never delivers a finish_reason chunk (truncated/aborted):
        // complete() should emit FinalUsage + Completed exactly once.
        $mapper = self::fixture();
        self::primeStart($mapper);

        // Feed a content delta with no finish_reason.
        $event = new Event('', [
            'choices' => [[
                'index'         => 0,
                'delta'         => ['content' => 'Zeus commands the phalanx.'],
                'finish_reason' => null,
            ]],
        ]);
        iterator_to_array($mapper->translate($event), preserve_keys: false);

        $cues = iterator_to_array($mapper->complete(), preserve_keys: false);

        $finals    = array_values(array_filter($cues, static fn ($c) => $c instanceof FinalUsage));
        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof Completed));

        self::assertCount(1, $finals);
        self::assertCount(1, $completed);

        // Calling complete() again must be a no-op — no duplicate emission.
        $second = iterator_to_array($mapper->complete(), preserve_keys: false);
        self::assertCount(0, $second);
    }

    #[Test]
    public function streamWithFinishReasonAndInlineUsageEmitsCompletedOnce(): void
    {
        // When usage arrives in the SAME chunk as finish_reason, complete() must
        // be a no-op — the terminal cues were already emitted inside translate().
        $mapper = self::fixture();
        self::primeStart($mapper);

        $event = new Event('', [
            'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
        iterator_to_array($mapper->translate($event), preserve_keys: false);

        // complete() after inline-usage terminal path must emit nothing.
        $cues = iterator_to_array($mapper->complete(), preserve_keys: false);

        self::assertCount(0, $cues);
    }

    #[Test]
    public function streamWithFinishReasonThenUsageChunkEmitsCompletedOnce(): void
    {
        // When finish_reason arrives in one chunk and usage in a SEPARATE subsequent
        // chunk (stream_options.include_usage=true pattern), the usage chunk triggers
        // FinalUsage + Completed and complete() is a no-op.
        $mapper = self::fixture();
        self::primeStart($mapper);

        // Chunk 1: finish_reason only.
        $stop = new Event('', [
            'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']],
        ]);
        iterator_to_array($mapper->translate($stop), preserve_keys: false);

        // Chunk 2: usage-only (no choices key).
        $usage = new Event('', [
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 8, 'total_tokens' => 28],
        ]);
        $cues = iterator_to_array($mapper->translate($usage), preserve_keys: false);

        $finals    = array_values(array_filter($cues, static fn ($c) => $c instanceof FinalUsage));
        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof Completed));

        self::assertCount(1, $finals);
        self::assertCount(1, $completed);
        self::assertSame(20, $finals[0]->inputTokens);
        self::assertSame(8, $finals[0]->outputTokens);

        // complete() must emit nothing — the usage chunk already closed the stream.
        $noop = iterator_to_array($mapper->complete(), preserve_keys: false);
        self::assertCount(0, $noop);
    }

    #[Test]
    public function ignoredEventsAloneDoNotSynthesizeCompleted(): void
    {
        // Feeding only typed events (which ChatCueMapper ignores) must not flip
        // $started — complete() must then emit nothing.
        $mapper = self::fixture();

        // This is a Responses API event type; Chat mapper returns early on non-empty type.
        $event = new Event('response.created', ['type' => 'response.created']);
        iterator_to_array($mapper->translate($event), preserve_keys: false);

        $cues = iterator_to_array($mapper->complete(), preserve_keys: false);

        self::assertCount(0, $cues);
    }

    #[Test]
    public function typedEventYieldsNoCues(): void
    {
        // ResponsesProvider events have a non-empty type — ChatCueMapper ignores them.
        $mapper = self::fixture();
        $event  = new Event('response.created', ['type' => 'response.created']);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(0, $cues);
    }

    #[Test]
    public function completeAfterStartedEmitsFinalUsageThenCompleted(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $cues = iterator_to_array($mapper->complete(), preserve_keys: false);

        self::assertCount(2, $cues);
        self::assertInstanceOf(FinalUsage::class, $cues[0]);
        self::assertInstanceOf(Completed::class, $cues[1]);
    }

    #[Test]
    public function usageTokensAccumulatedIntoFinalUsage(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        // Chunk with finish_reason + inline usage: the new inline-completion path
        // emits FinalUsage + Completed inside translate() when usage arrives in
        // the same chunk as finish_reason. complete() is therefore a no-op.
        $event = new Event('', [
            'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 25, 'total_tokens' => 75],
        ]);
        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        $finalUsage = array_values(array_filter($cues, static fn ($c) => $c instanceof FinalUsage));

        self::assertCount(1, $finalUsage);
        self::assertSame(50, $finalUsage[0]->inputTokens);
        self::assertSame(25, $finalUsage[0]->outputTokens);
    }

    #[Test]
    public function sequenceNumbersIncrementAcrossEvents(): void
    {
        $mapper = self::fixture();

        // Feed the role chunk — sets $started = true, emits Resolved + Started.
        $cues1 = iterator_to_array($mapper->translate(new Event('', [
            'model'   => 'gpt-5',
            'choices' => [['index' => 0, 'delta' => ['role' => 'assistant'], 'finish_reason' => null]],
        ])), preserve_keys: false);

        // complete() is now a defensive terminator — emits FinalUsage + Completed
        // only because $started = true and $completed = false.
        $cues2 = iterator_to_array($mapper->complete(), preserve_keys: false);

        // Resolved=0, Started=1, FinalUsage=2, Completed=3
        self::assertSame(0, $cues1[0]->sequence);
        self::assertSame(1, $cues1[1]->sequence);
        self::assertSame(2, $cues2[0]->sequence);
        self::assertSame(3, $cues2[1]->sequence);
    }

    private static function fixture(): ChatCueMapper
    {
        return new ChatCueMapper(self::invocation());
    }

    private static function primeStart(ChatCueMapper $mapper): void
    {
        iterator_to_array($mapper->translate(new Event('', [
            'model'   => 'gpt-5',
            'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => null]],
        ])), preserve_keys: false);
    }

    private static function invocation(): Invocation
    {
        return Invocation::of(
            id: 'inv_achilles',
            agentId: 'achilles',
            activityId: 'act_marathon',
            contextHash: str_repeat('c', 64),
            instructions: 'Defend the pass. Honour the phalanx.',
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::FileRead),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming(),
        );
    }
}
