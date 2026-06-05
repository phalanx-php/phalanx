<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Provider\OpenAI;

use Phalanx\AiProviders\Artifact\Kind as ArtifactKind;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Cue\Effect\ArgumentsDelta;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Cue\Invocation\Completed;
use Phalanx\AiProviders\Cue\Invocation\Failed;
use Phalanx\AiProviders\Cue\Invocation\Started;
use Phalanx\AiProviders\Cue\Output\Channel;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\AiProviders\Cue\Output\TokenStop;
use Phalanx\AiProviders\Cue\Provider\Resolved;
use Phalanx\AiProviders\Cue\StopReason;
use Phalanx\AiProviders\Cue\Usage\FinalUsage;
use Phalanx\AiProviders\Effect\Kind as EffectKind;
use Phalanx\AiProviders\Effects;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Output;
use Phalanx\AiProviders\Provider\Config\Model;
use Phalanx\AiProviders\Provider\Needs as ProviderNeeds;
use Phalanx\AiProviders\Provider\OpenAI\ResponsesOptions;
use Phalanx\AiProviders\Provider\OpenAI\ResponsesProvider;
use Phalanx\AiProviders\Provider\Preference;
use Phalanx\AiProviders\Runtime\Sync\Runtime;
use Phalanx\AiProviders\Transport\Fake\Transport as FakeTransport;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponsesProviderTest extends TestCase
{
    #[Test]
    public function simpleFixtureEmitsExpectedCueTypes(): void
    {
        $provider = self::provider(self::script('responses-simple.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $types = array_map(static fn ($c) => $c::class, $cues);

        self::assertContains(Resolved::class, $types);
        self::assertContains(Started::class, $types);
        self::assertContains(TokenDelta::class, $types);
        self::assertContains(FinalUsage::class, $types);
        self::assertContains(Completed::class, $types);
    }

    #[Test]
    public function simpleFixtureTranscriptAssembles(): void
    {
        $provider = self::provider(self::script('responses-simple.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());

        $transcript = '';
        foreach ($stream->tokens() as $cue) {
            if ($cue instanceof TokenDelta) {
                $transcript .= $cue->text;
            }
        }

        self::assertSame('Apollo led the phalanx at Marathon.', $transcript);
    }

    #[Test]
    public function toolCallFixtureEmitsEffectRequestedAndArgumentsDeltas(): void
    {
        $provider = self::provider(self::script('responses-tool-call.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $types = array_map(static fn ($c) => $c::class, $cues);

        self::assertContains(Requested::class, $types);
        self::assertContains(ArgumentsDelta::class, $types);
    }

    #[Test]
    public function reasoningFixtureEmitsReasoningChannelDelta(): void
    {
        $provider = self::provider(self::script('responses-reasoning.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $reasoningDeltas = array_values(array_filter(
            $cues,
            static fn ($c) => $c instanceof TokenDelta && $c->channel === Channel::Reasoning,
        ));

        self::assertNotEmpty($reasoningDeltas);
    }

    #[Test]
    public function failedEventEmitsFailedCue(): void
    {
        // response.failed is the real OpenAI wire event; this fixture captures
        // the shape. Asserts that a Failed cue is emitted with the correct reason.
        $provider = self::provider(self::script('responses-failed.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $failed = array_values(array_filter($cues, static fn ($c) => $c instanceof Failed));

        self::assertCount(1, $failed);
        self::assertStringContainsString('oracle', $failed[0]->reason);
        self::assertSame('server_error', $failed[0]->errorClass);
        // Failed is terminal — no Completed must follow it.
        self::assertCount(0, array_filter($cues, static fn ($c) => $c instanceof Completed));
    }

    #[Test]
    public function responseFailedYieldsInvocationFailed(): void
    {
        // responses-failed.sse: response.created fires first (stream starts), then
        // response.failed arrives and transport closes. Contract: exactly one Failed, zero Completed.
        $provider = self::provider(self::script('responses-failed.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $failed = array_values(array_filter($cues, static fn ($c) => $c instanceof Failed));
        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof Completed));

        self::assertCount(1, $failed);
        self::assertCount(0, $completed);
    }

    #[Test]
    public function toolCallFixtureStopReasonIsToolUse(): void
    {
        $provider = self::provider(self::script('responses-tool-call.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());

        $stops = $stream->ofKind(TokenStop::class)->toArray();

        self::assertCount(1, $stops);
        self::assertInstanceOf(TokenStop::class, $stops[0]);
        self::assertSame(StopReason::ToolUse, $stops[0]->reason);
    }

    #[Test]
    public function streamEndingWithoutWireTerminatorStillEmitsCompleted(): void
    {
        // A stream that ends before response.completed (transport truncation).
        // The defensive complete() wired in ResponsesProvider::perform() must emit
        // exactly one Completed — no duplicate, no missing.
        $provider = self::provider(self::script('responses-truncated.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof Completed));

        self::assertCount(1, $completed);
        // Partial output must be present — the truncated fixture emits a TokenDelta before cutting off.
        self::assertNotEmpty(array_filter($cues, static fn ($c) => $c instanceof TokenDelta));
    }

    #[Test]
    public function cancellationMidStreamHaltsIteration(): void
    {
        $runtime = new Runtime();
        $provider = self::provider(self::script('responses-simple.sse'));
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
        } catch (\Phalanx\AiProviders\Runtime\CancellationException) {
            $cancelled = true;
        }

        self::assertGreaterThanOrEqual(1, $count);
        self::assertTrue($cancelled);
        self::assertTrue($runtime->isCancelled());
    }

    #[Test]
    public function performIsLazyAndDoesNotStartStreamBeforeIteration(): void
    {
        // perform() must return a Stream without invoking transport. Only
        // iterating the stream triggers transport.stream().
        $stub = new class implements \Phalanx\AiProviders\Transport {
            public bool $called = false;

            public function stream(
                \Phalanx\AiProviders\Transport\Request $request,
                \Phalanx\AiProviders\Runtime $runtime,
            ): \Generator {
                $this->called = true;
                yield 'data: {}' . "\n\n";
            }
        };

        $provider = new ResponsesProvider(
            transport: $stub,
            apiKey: 'key_test',
            model: self::model(),
        );

        $stream = $provider->perform(self::invocation(), new Runtime());

        // Transport must NOT have been called yet.
        self::assertFalse($stub->called);

        // Consume the stream — transport is now invoked.
        iterator_to_array($stream);

        self::assertTrue($stub->called);
    }

    #[Test]
    public function capabilitiesReadFromModel(): void
    {
        $model = Model::of(
            name: 'o3-reasoning',
            modelId: 'o3',
            aliases: ['o3'],
            capabilities: Capabilities::of(Capability::Reasoning),
        );
        $provider = new ResponsesProvider(
            transport: new FakeTransport([]),
            apiKey: 'key_delphi',
            model: $model,
        );

        self::assertSame($model->capabilities, $provider->capabilities());
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

        return ['POST https://api.openai.com/v1/responses' => $chunks];
    }

    /** @param array<string, list<string>> $script */
    private static function provider(array $script, ?ResponsesOptions $options = null): ResponsesProvider
    {
        return new ResponsesProvider(
            transport: new FakeTransport($script),
            apiKey: 'key_olympus',
            model: self::model(),
            responsesOptions: $options ?? new ResponsesOptions(),
        );
    }

    private static function invocation(): Invocation
    {
        return Invocation::of(
            id: 'inv_pericles',
            agentId: 'pericles',
            activityId: 'act_agora',
            contextHash: str_repeat('f', 64),
            instructions: 'Lead the phalanx with wisdom.',
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::FileRead),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming(),
            dynamicContext: ['user_input' => 'What is the battle plan?'],
        );
    }

    private static function model(): Model
    {
        return Model::of(
            name: 'o3',
            modelId: 'o3',
            aliases: ['o3-latest'],
            capabilities: Capabilities::of(Capability::Reasoning, Capability::ToolUse),
        );
    }
}
