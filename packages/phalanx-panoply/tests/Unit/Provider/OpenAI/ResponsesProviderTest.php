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
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\OpenAI\ResponsesOptions;
use Phalanx\Panoply\Provider\OpenAI\ResponsesProvider;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Runtime\Sync\Runtime;
use Phalanx\Panoply\Transport\Fake\Transport as FakeTransport;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponsesProviderTest extends TestCase
{
    #[Test]
    public function simpleFixtureEmitsExpectedCueTypes(): void
    {
        $provider = self::provider(self::script('responses-simple.sse'));
        $stream   = $provider->perform(self::invocation(), new Runtime());
        $cues     = $stream->toArray();

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
        $stream   = $provider->perform(self::invocation(), new Runtime());

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
        $stream   = $provider->perform(self::invocation(), new Runtime());
        $cues     = $stream->toArray();

        $types = array_map(static fn ($c) => $c::class, $cues);

        self::assertContains(Requested::class, $types);
        self::assertContains(ArgumentsDelta::class, $types);
    }

    #[Test]
    public function reasoningFixtureEmitsReasoningChannelDelta(): void
    {
        $provider = self::provider(self::script('responses-reasoning.sse'));
        $stream   = $provider->perform(self::invocation(), new Runtime());
        $cues     = $stream->toArray();

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
        $stream   = $provider->perform(self::invocation(), new Runtime());
        $cues     = $stream->toArray();

        $failed = array_values(array_filter($cues, static fn ($c) => $c instanceof Failed));

        self::assertCount(1, $failed);
        self::assertStringContainsString('oracle', $failed[0]->reason);
        self::assertSame('server_error', $failed[0]->errorClass);
    }

    #[Test]
    public function toolCallFixtureStopReasonIsToolUse(): void
    {
        $provider = self::provider(self::script('responses-tool-call.sse'));
        $stream   = $provider->perform(self::invocation(), new Runtime());

        $stops = $stream->ofKind(TokenStop::class)->toArray();

        self::assertCount(1, $stops);
        self::assertSame(StopReason::ToolUse, $stops[0]->reason);
    }

    #[Test]
    public function streamEndingWithoutWireTerminatorStillEmitsCompleted(): void
    {
        // A stream that ends before response.completed (transport truncation).
        // The defensive complete() wired in ResponsesProvider::perform() must emit
        // exactly one Completed — no duplicate, no missing.
        $provider = self::provider(self::script('responses-truncated.sse'));
        $stream   = $provider->perform(self::invocation(), new Runtime());
        $cues     = $stream->toArray();

        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof Completed));

        self::assertCount(1, $completed);
    }

    #[Test]
    public function cancellationMidStreamHaltsIteration(): void
    {
        $runtime  = new Runtime();
        $provider = self::provider(self::script('responses-simple.sse'));
        $stream   = $provider->perform(self::invocation(), $runtime);

        $count     = 0;
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
        $model    = Model::of(
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
        $raw  = file_get_contents($path);

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
