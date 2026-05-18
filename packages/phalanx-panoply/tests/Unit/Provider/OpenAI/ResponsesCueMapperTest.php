<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\OpenAI;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Cue\Effect\ArgumentsDelta;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Invocation\Completed as InvocationCompleted;
use Phalanx\Panoply\Cue\Invocation\Failed;
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
use Phalanx\Panoply\Provider\OpenAI\ResponsesCueMapper;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Sse\Event;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponsesCueMapperTest extends TestCase
{
    #[Test]
    public function responseCreatedYieldsResolvedThenStarted(): void
    {
        $mapper = self::fixture();
        $event = new Event('response.created', [
            'response' => ['id' => 'resp_01', 'model' => 'o3'],
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
        $event = new Event('response.created', ['response' => ['model' => 'o3']]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertSame('openai', $cues[0]->provider);
        self::assertSame('o3', $cues[0]->model);
    }

    #[Test]
    public function outputTextDeltaYieldsTokenDeltaOnMessageChannel(): void
    {
        $mapper = self::fixture();
        $event = new Event('response.output_text.delta', ['delta' => 'Leonidas stood firm at Thermopylae.']);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(1, $cues);
        self::assertInstanceOf(TokenDelta::class, $cues[0]);
        self::assertSame(Channel::Message, $cues[0]->channel);
        self::assertSame('Leonidas stood firm at Thermopylae.', $cues[0]->text);
    }

    #[Test]
    public function reasoningDeltaYieldsTokenDeltaOnReasoningChannel(): void
    {
        $mapper = self::fixture();
        $event = new Event('response.reasoning.delta', ['delta' => 'Apollo considers the strategy.']);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(1, $cues);
        self::assertInstanceOf(TokenDelta::class, $cues[0]);
        self::assertSame(Channel::Reasoning, $cues[0]->channel);
    }

    #[Test]
    public function functionCallCreatedYieldsEffectRequested(): void
    {
        $mapper = self::fixture();
        $event = new Event('response.function_call.created', [
            'item' => ['id' => 'fc_agora01', 'name' => 'search_records', 'type' => 'function_call'],
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(1, $cues);
        self::assertInstanceOf(Requested::class, $cues[0]);
        self::assertSame('fc_fc_agora01', $cues[0]->effectId);
        self::assertStringContainsString('search_records', $cues[0]->summary);
    }

    #[Test]
    public function functionCallArgumentsDeltaYieldsArgumentsDelta(): void
    {
        $mapper = self::fixture();

        // Register the function call first.
        iterator_to_array($mapper->translate(new Event('response.function_call.created', [
            'item' => ['id' => 'fc_sparta01', 'name' => 'rally', 'type' => 'function_call'],
        ])), preserve_keys: false);

        $event = new Event('response.function_call_arguments.delta', [
            'item_id' => 'fc_sparta01',
            'delta' => '{"count":300}',
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(1, $cues);
        self::assertInstanceOf(ArgumentsDelta::class, $cues[0]);
        self::assertSame('fc_fc_sparta01', $cues[0]->effectId);
        self::assertSame('{"count":300}', $cues[0]->jsonDelta);
    }

    #[Test]
    public function responseCompletedWithoutToolCallsStopReasonIsEndOfTurn(): void
    {
        $mapper = self::fixture();
        $event = new Event('response.completed', [
            'response' => ['status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]],
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);
        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));

        self::assertCount(1, $stops);
        self::assertSame(StopReason::EndOfTurn, $stops[0]->reason);
    }

    #[Test]
    public function responseCompletedAfterToolCallStopReasonIsToolUse(): void
    {
        // When response.function_call.created fires before response.completed,
        // the mapper must emit StopReason::ToolUse for both TokenStop and Completed.
        $mapper = self::fixture();

        // Register a function call — sets $hasToolCalls = true.
        iterator_to_array($mapper->translate(new Event('response.function_call.created', [
            'item' => ['id' => 'fc_agora99', 'name' => 'rally_hoplites', 'type' => 'function_call'],
        ])), preserve_keys: false);

        $completedEvent = new Event('response.completed', [
            'response' => ['status' => 'completed', 'usage' => ['input_tokens' => 30, 'output_tokens' => 12]],
        ]);

        $cues = iterator_to_array($mapper->translate($completedEvent), preserve_keys: false);
        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));
        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof InvocationCompleted));

        self::assertCount(1, $stops);
        self::assertSame(StopReason::ToolUse, $stops[0]->reason);
        self::assertCount(1, $completed);
        self::assertSame(StopReason::ToolUse, $completed[0]->stopReason);
    }

    #[Test]
    public function responseCompletedYieldsTokenStopThenFinalUsageThenCompleted(): void
    {
        $mapper = self::fixture();
        $event = new Event('response.completed', [
            'response' => [
                'status' => 'completed',
                'usage' => ['input_tokens' => 40, 'output_tokens' => 20, 'total_tokens' => 60],
            ],
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(3, $cues);
        self::assertInstanceOf(TokenStop::class, $cues[0]);
        self::assertInstanceOf(FinalUsage::class, $cues[1]);
        self::assertInstanceOf(InvocationCompleted::class, $cues[2]);
        self::assertSame(40, $cues[1]->inputTokens);
        self::assertSame(20, $cues[1]->outputTokens);
    }

    #[Test]
    public function responseFailedYieldsInvocationFailed(): void
    {
        // OpenAI Responses API uses response.failed (not response.error).
        // Error info is nested under response.error in the payload.
        $mapper = self::fixture();
        $event = new Event('response.failed', [
            'response' => [
                'id' => 'resp_FAIL01',
                'status' => 'failed',
                'error' => ['code' => 'server_error', 'message' => 'Olympus overloaded.'],
            ],
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(1, $cues);
        self::assertInstanceOf(Failed::class, $cues[0]);
        self::assertSame('Olympus overloaded.', $cues[0]->reason);
        self::assertSame('server_error', $cues[0]->errorClass);
    }

    #[Test]
    public function responseFailedWithTopLevelMessageFallsBack(): void
    {
        // Some error shapes only have top-level message/code (not nested under response.error).
        $mapper = self::fixture();
        $event = new Event('response.failed', [
            'message' => 'Marathon dispatch failed.',
            'code' => 'timeout',
        ]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(1, $cues);
        self::assertInstanceOf(Failed::class, $cues[0]);
        self::assertSame('Marathon dispatch failed.', $cues[0]->reason);
        self::assertSame('timeout', $cues[0]->errorClass);
    }

    #[Test]
    public function legacyResponseErrorEventYieldsNoCues(): void
    {
        // response.error is NOT the real OpenAI wire event — it must yield nothing
        // (forward-compat unknown-event path) rather than silently handling errors.
        $mapper = self::fixture();
        $event = new Event('response.error', ['message' => 'should be ignored']);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(0, $cues);
    }

    #[Test]
    public function unknownEventTypeYieldsNoCues(): void
    {
        $mapper = self::fixture();
        $event = new Event('response.rate_limit', []);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(0, $cues);
    }

    #[Test]
    public function ignoredEventsAloneDoNotSynthesizeCompleted(): void
    {
        // Feeding only unknown event types must not flip $started — complete()
        // must emit nothing when no response.created was seen.
        $mapper = self::fixture();

        $event = new Event('response.rate_limit', ['message' => 'back off']);
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
    public function completeAfterStartedWithoutWireTerminatorEmitsTokenStopFinalUsageCompleted(): void
    {
        $mapper = self::fixture();

        // Feed only response.created — stream starts but response.completed never arrives.
        iterator_to_array($mapper->translate(new Event('response.created', [
            'response' => ['id' => 'resp_TRUNC01', 'model' => 'o3'],
        ])), preserve_keys: false);

        $cues = iterator_to_array($mapper->complete(), preserve_keys: false);

        self::assertCount(3, $cues);
        self::assertInstanceOf(TokenStop::class, $cues[0]);
        self::assertInstanceOf(FinalUsage::class, $cues[1]);
        self::assertInstanceOf(InvocationCompleted::class, $cues[2]);
    }

    #[Test]
    public function completeAfterCleanShutdownYieldsNothing(): void
    {
        $mapper = self::fixture();

        iterator_to_array($mapper->translate(new Event('response.created', [
            'response' => ['model' => 'o3'],
        ])), preserve_keys: false);

        // Feed a full response.completed — wire-native terminator.
        iterator_to_array($mapper->translate(new Event('response.completed', [
            'response' => ['status' => 'completed', 'usage' => ['input_tokens' => 8, 'output_tokens' => 4]],
        ])), preserve_keys: false);

        // complete() must be a guarded no-op.
        $cues = iterator_to_array($mapper->complete(), preserve_keys: false);

        self::assertCount(0, $cues);
    }

    #[Test]
    public function sequenceNumbersIncrementAcrossEvents(): void
    {
        $mapper = self::fixture();

        $cues1 = iterator_to_array($mapper->translate(new Event('response.created', [
            'response' => ['model' => 'o3'],
        ])), preserve_keys: false);

        $cues2 = iterator_to_array($mapper->translate(new Event('response.completed', [
            'response' => ['status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]],
        ])), preserve_keys: false);

        // Resolved=0, Started=1, TokenStop=2, FinalUsage=3, Completed=4
        self::assertSame(0, $cues1[0]->sequence);
        self::assertSame(1, $cues1[1]->sequence);
        self::assertSame(2, $cues2[0]->sequence);
        self::assertSame(3, $cues2[1]->sequence);
        self::assertSame(4, $cues2[2]->sequence);
    }

    private static function fixture(): ResponsesCueMapper
    {
        return new ResponsesCueMapper(self::invocation());
    }

    private static function invocation(): Invocation
    {
        return Invocation::of(
            id: 'inv_odysseus',
            agentId: 'odysseus',
            activityId: 'act_ithaca',
            contextHash: str_repeat('e', 64),
            instructions: 'Think like an Olympian.',
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::FileRead),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming(),
        );
    }
}
