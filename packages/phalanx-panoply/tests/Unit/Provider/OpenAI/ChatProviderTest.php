<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\OpenAI;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Cue\Effect\ArgumentsDelta;
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
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\OpenAI\ChatOptions;
use Phalanx\Panoply\Provider\OpenAI\ChatProvider;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Runtime\Sync\Runtime;
use Phalanx\Panoply\Transport\Fake\Transport as FakeTransport;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end provider tests. Uses {@see FakeTransport} scripted with
 * recorded SSE fixture files — no live network calls.
 */
final class ChatProviderTest extends TestCase
{
    #[Test]
    public function simpleFixtureEmitsExpectedCueTypes(): void
    {
        $provider = self::provider(self::script('chat-simple.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $types = array_map(static fn ($c) => $c::class, $cues);

        self::assertContains(Resolved::class, $types);
        self::assertContains(Started::class, $types);
        self::assertContains(TokenDelta::class, $types);
        self::assertContains(TokenStop::class, $types);
        self::assertContains(FinalUsage::class, $types);
        self::assertContains(Completed::class, $types);
    }

    #[Test]
    public function simpleFixtureTranscriptAssembles(): void
    {
        $provider = self::provider(self::script('chat-simple.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());

        $transcript = '';
        foreach ($stream->tokens() as $cue) {
            if ($cue instanceof TokenDelta) {
                $transcript .= $cue->text;
            }
        }

        self::assertSame('Leonidas held the pass at Thermopylae.', $transcript);
    }

    #[Test]
    public function simpleFixtureStopReasonIsEndOfTurn(): void
    {
        $provider = self::provider(self::script('chat-simple.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());

        $stops = $stream->ofKind(TokenStop::class)->toArray();

        self::assertCount(1, $stops);
        self::assertSame(StopReason::EndOfTurn, $stops[0]->reason);
    }

    #[Test]
    public function toolCallFixtureEmitsEffectRequestedAndArgumentsDeltas(): void
    {
        $provider = self::provider(self::script('chat-tool-call.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $types = array_map(static fn ($c) => $c::class, $cues);

        self::assertContains(Requested::class, $types);
        self::assertContains(ArgumentsDelta::class, $types);
    }

    #[Test]
    public function toolCallFixtureStopReasonIsToolUse(): void
    {
        $provider = self::provider(self::script('chat-tool-call.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());

        $stops = $stream->ofKind(TokenStop::class)->toArray();

        self::assertCount(1, $stops);
        self::assertSame(StopReason::ToolUse, $stops[0]->reason);
    }

    #[Test]
    public function parallelToolsFixtureEmitsTwoEffectRequested(): void
    {
        $provider = self::provider(self::script('chat-parallel-tools.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $requested = array_values(array_filter($cues, static fn ($c) => $c instanceof Requested));

        self::assertCount(2, $requested);
        self::assertNotSame($requested[0]->effectId, $requested[1]->effectId);

        // Verify argument deltas route to the correct effectId without cross-contamination.
        // Fixture: index 0 (call_apollo01 / query_olympus) gets {"location": "Delphi"}
        //          index 1 (call_leonidas01 / rally_hoplites) gets {"count": 300}
        $apolloId = 'tc_call_apollo01';
        $leonidasId = 'tc_call_leonidas01';

        /** @var list<ArgumentsDelta> $deltas */
        $deltas = array_values(array_filter($cues, static fn ($c) => $c instanceof ArgumentsDelta));

        $apolloDeltas = array_values(array_filter($deltas, static fn ($d) => $d->effectId === $apolloId));
        $leonidasDeltas = array_values(array_filter($deltas, static fn ($d) => $d->effectId === $leonidasId));

        // Apollo received the location argument fragments.
        $apolloArgs = implode('', array_map(static fn ($d) => $d->jsonDelta, $apolloDeltas));
        self::assertStringContainsString('"location"', $apolloArgs);

        // Leonidas received the count argument fragments.
        $leonidasArgs = implode('', array_map(static fn ($d) => $d->jsonDelta, $leonidasDeltas));
        self::assertStringContainsString('"count"', $leonidasArgs);

        // No cross-contamination: Apollo has no count, Leonidas has no location.
        self::assertStringNotContainsString('"count"', $apolloArgs);
        self::assertStringNotContainsString('"location"', $leonidasArgs);
    }

    #[Test]
    public function doneSentinelFixtureCompletesNormally(): void
    {
        $provider = self::provider(self::script('chat-done-sentinel.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof Completed));

        self::assertCount(1, $completed);
    }

    #[Test]
    public function streamEndingWithoutFinishReasonStillEmitsCompleted(): void
    {
        // A stream that ends with [DONE] but never emits a finish_reason chunk
        // (truncated or non-standard provider). The defensive complete() path
        // must still emit exactly one Completed — no duplicate, no missing.
        $provider = self::provider(self::script('chat-no-finish-reason.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof Completed));

        self::assertCount(1, $completed);
    }

    #[Test]
    public function errorFixtureEmitsInvocationFailed(): void
    {
        // A mid-stream error chunk (rate limit / content filter). The error
        // handler added to ChatCueMapper must emit Failed rather than silently
        // swallowing the payload.
        $provider = self::provider(self::script('chat-error.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $failed = array_values(array_filter($cues, static fn ($c) => $c instanceof Failed));

        self::assertCount(1, $failed);
        self::assertStringContainsString('Delphi rate limit', $failed[0]->reason);
        self::assertSame('rate_limit_exceeded', $failed[0]->errorClass);
        // Failed is terminal — no Completed must follow it.
        self::assertCount(0, array_filter($cues, static fn ($c) => $c instanceof Completed));
    }

    #[Test]
    public function errorMidStreamThenTransportCloseEmitsFailedExactlyOnceWithoutCompleted(): void
    {
        // chat-error.sse: role chunk fires first (stream starts), then a top-level
        // error object arrives and the transport closes. Contract: exactly one Failed, zero Completed.
        $provider = self::provider(self::script('chat-error.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $failed = array_values(array_filter($cues, static fn ($c) => $c instanceof Failed));
        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof Completed));

        self::assertCount(1, $failed);
        self::assertCount(0, $completed);
    }

    #[Test]
    public function cancellationMidStreamHaltsIteration(): void
    {
        $runtime = new Runtime();
        $provider = self::provider(self::script('chat-simple.sse'));
        $stream = $provider->perform(self::invocation(), $runtime);

        $count = 0;
        $cancelled = false;
        try {
            foreach ($stream as $cue) {
                $count++;
                if ($count === 1) {
                    $runtime->cancel();
                }
            }
        } catch (\Phalanx\Panoply\Runtime\CancellationException) {
            $cancelled = true;
        }

        self::assertGreaterThanOrEqual(1, $count);
        self::assertTrue($cancelled);
        self::assertTrue($runtime->isCancelled());
    }

    #[Test]
    public function streamEndingWithoutWireTerminatorStillEmitsCompleted(): void
    {
        // A stream truncated before finish_reason. complete() defensive path must
        // emit exactly one Completed.
        $provider = self::provider(self::script('chat-truncated.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof Completed));

        self::assertCount(1, $completed);
        // Partial output must be present — the truncated fixture emits a TokenDelta before cutting off.
        self::assertNotEmpty(array_filter($cues, static fn ($c) => $c instanceof TokenDelta));
    }

    #[Test]
    public function capabilitiesReadFromModel(): void
    {
        $model = Model::of(
            name: 'apollo-vision',
            modelId: 'apollo-vision',
            aliases: ['apollo'],
            capabilities: Capabilities::of(Capability::Vision),
        );
        $provider = new ChatProvider(
            transport: new FakeTransport([]),
            apiKey: 'key_olympus',
            model: $model,
        );

        self::assertSame($model->capabilities, $provider->capabilities());
    }

    #[Test]
    public function performIsLazyAndDoesNotStartStreamBeforeIteration(): void
    {
        // perform() must return a Stream without invoking transport. Only
        // iterating the stream triggers transport.stream().
        $stub = new class implements \Phalanx\Panoply\Transport {
            public bool $called = false;

            public function stream(
                \Phalanx\Panoply\Transport\Request $request,
                \Phalanx\Panoply\Runtime $runtime,
            ): \Generator {
                $this->called = true;
                yield 'data: {}' . "\n\n";
            }
        };

        $provider = new ChatProvider(
            transport: $stub,
            apiKey: 'key_test',
            model: self::model(),
        );

        $stream = $provider->perform(self::invocation(), new Runtime());

        self::assertFalse($stub->called);

        iterator_to_array($stream);

        self::assertTrue($stub->called);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function script(string $fixture): array
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/Provider/OpenAI/' . $fixture;
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException("Fixture not found: {$path}");
        }

        $chunks = array_values(array_filter(
            array_map(
                static fn (string $s): string => $s . "\n\n",
                explode("\n\n", rtrim($raw, "\n")),
            ),
            static fn (string $s): bool => trim($s) !== '',
        ));

        return ['POST https://api.openai.com/v1/chat/completions' => $chunks];
    }

    private static function provider(array $script, ?ChatOptions $options = null): ChatProvider
    {
        return new ChatProvider(
            transport: new FakeTransport($script),
            apiKey: 'key_sparta',
            model: self::model(),
            chatOptions: $options ?? new ChatOptions(),
        );
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
            dynamicContext: ['user_input' => 'What is your battle plan?'],
        );
    }

    private static function model(): Model
    {
        return Model::of(
            name: 'gpt-5',
            modelId: 'gpt-5',
            aliases: ['gpt5'],
            capabilities: Capabilities::of(Capability::Reasoning, Capability::ToolUse),
        );
    }
}
