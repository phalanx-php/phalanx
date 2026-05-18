<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\HuggingFace;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Cue\Effect\ArgumentsDelta;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Invocation\Completed;
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
use Phalanx\Panoply\Provider\HuggingFace\InferenceProvider;
use Phalanx\Panoply\Provider\HuggingFace\Options;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Runtime\Sync\Runtime;
use Phalanx\Panoply\Transport\Fake\Transport as FakeTransport;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end provider tests. Uses {@see FakeTransport} scripted with
 * recorded SSE fixture files — no live network calls.
 *
 * The Hugging Face Inference API wire format is byte-for-byte OpenAI Chat
 * Completions-compatible. The fixtures mirror OpenAI's chunk structure with
 * HuggingFace model IDs (meta-llama/...) in the `model` field.
 */
final class InferenceProviderTest extends TestCase
{
    #[Test]
    public function simpleFixtureEmitsExpectedCueTypes(): void
    {
        $provider = self::provider(self::script('chat-simple.sse'));
        $stream   = $provider->perform(self::invocation(), new Runtime());
        $cues     = $stream->toArray();

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
        $stream   = $provider->perform(self::invocation(), new Runtime());

        $transcript = '';
        foreach ($stream->tokens() as $cue) {
            if ($cue instanceof TokenDelta) {
                $transcript .= $cue->text;
            }
        }

        self::assertSame('Themistocles commands the fleet.', $transcript);
    }

    #[Test]
    public function resolvedCueCarriesHuggingFaceProvider(): void
    {
        // This is the critical test: proves the providerId composition works.
        // ChatCueMapper is constructed with providerId: 'huggingface', so the
        // Resolved cue must carry 'huggingface', not 'openai'.
        $provider = self::provider(self::script('chat-simple.sse'));
        $stream   = $provider->perform(self::invocation(), new Runtime());

        $resolved = $stream->ofKind(Resolved::class)->toArray();

        self::assertCount(1, $resolved);
        self::assertSame('huggingface', $resolved[0]->provider);
    }

    #[Test]
    public function simpleFixtureStopReasonIsEndOfTurn(): void
    {
        $provider = self::provider(self::script('chat-simple.sse'));
        $stream   = $provider->perform(self::invocation(), new Runtime());

        $stops = $stream->ofKind(TokenStop::class)->toArray();

        self::assertCount(1, $stops);
        self::assertSame(StopReason::EndOfTurn, $stops[0]->reason);
    }

    #[Test]
    public function toolCallFixtureEmitsEffectRequestedAndArgumentsDeltas(): void
    {
        $provider = self::provider(self::script('chat-tool-call.sse'));
        $stream   = $provider->perform(self::invocation(), new Runtime());
        $cues     = $stream->toArray();

        $types = array_map(static fn ($c) => $c::class, $cues);

        self::assertContains(Requested::class, $types);
        self::assertContains(ArgumentsDelta::class, $types);
    }

    #[Test]
    public function toolCallFixtureStopReasonIsToolUse(): void
    {
        $provider = self::provider(self::script('chat-tool-call.sse'));
        $stream   = $provider->perform(self::invocation(), new Runtime());

        $stops = $stream->ofKind(TokenStop::class)->toArray();

        self::assertCount(1, $stops);
        self::assertSame(StopReason::ToolUse, $stops[0]->reason);
    }

    #[Test]
    public function cancellationMidStreamHaltsIteration(): void
    {
        $runtime  = new Runtime();
        $provider = self::provider(self::script('chat-simple.sse'));
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
    public function capabilitiesReadFromModel(): void
    {
        $model    = Model::of(
            name: 'achilles-instruct',
            modelId: 'achaean/Achilles-Instruct',
            aliases: ['achilles'],
            capabilities: Capabilities::of(Capability::ToolUse),
        );
        $provider = new InferenceProvider(
            transport: new FakeTransport([]),
            apiKey: 'hf_tok_test',
            model: $model,
        );

        self::assertSame($model->capabilities, $provider->capabilities());
    }

    private static function provider(array $script, ?Options $options = null): InferenceProvider
    {
        return new InferenceProvider(
            transport: new FakeTransport($script),
            apiKey: 'hf_tok_themistocles',
            model: self::model(),
            options: $options ?? new Options(),
        );
    }

    /**
     * Reads a fixture .sse file and returns a FakeTransport script map.
     * The key matches the URL InferenceProvider builds.
     *
     * @return array<string, list<string>>
     */
    private static function script(string $fixture): array
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/Provider/HuggingFace/' . $fixture;
        $raw  = file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException("Fixture not found: {$path}");
        }

        $url = 'https://api-inference.huggingface.co/v1/chat/completions';

        $chunks = array_values(array_filter(
            array_map(
                static fn (string $s): string => $s . "\n\n",
                explode("\n\n", rtrim($raw, "\n")),
            ),
            static fn (string $s): bool => trim($s) !== '',
        ));

        return ['POST ' . $url => $chunks];
    }

    private static function invocation(): Invocation
    {
        return Invocation::of(
            id: 'inv_themistocles',
            agentId: 'themistocles',
            activityId: 'act_salamis',
            contextHash: str_repeat('b', 64),
            instructions: 'Command the Athenian fleet.',
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::FileRead),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming(),
            dynamicContext: ['user_input' => 'What is the naval strategy?'],
        );
    }

    private static function model(): Model
    {
        return Model::of(
            name: 'meta-llama-3.1-70b-instruct',
            modelId: 'meta-llama/Meta-Llama-3.1-70B-Instruct',
            aliases: ['llama-70b'],
            capabilities: Capabilities::of(Capability::ToolUse),
        );
    }
}
