<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Provider\Gemini;

use Phalanx\AiProviders\Artifact\Kind as ArtifactKind;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
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
use Phalanx\AiProviders\Provider\Gemini\Options;
use Phalanx\AiProviders\Provider\Gemini\Provider;
use Phalanx\AiProviders\Provider\Needs as ProviderNeeds;
use Phalanx\AiProviders\Provider\Preference;
use Phalanx\AiProviders\Runtime\Sync\Runtime;
use Phalanx\AiProviders\Transport\Fake\Transport as FakeTransport;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;
use Phalanx\Testing\FixtureFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end provider tests. Uses {@see FakeTransport} scripted with
 * recorded SSE fixture files — no live network calls.
 */
final class ProviderTest extends TestCase
{
    #[Test]
    public function simpleTextFixtureEmitsExpectedCueTypes(): void
    {
        $provider = self::provider(self::script('simple-text.sse'));
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
    public function simpleTextTranscriptAssembles(): void
    {
        $provider = self::provider(self::script('simple-text.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());

        $transcript = '';
        foreach ($stream->tokens() as $cue) {
            if ($cue instanceof TokenDelta) {
                $transcript .= $cue->text;
            }
        }

        self::assertSame('Poseidon commands the deep.', $transcript);
    }

    #[Test]
    public function simpleTextStopReasonIsEndOfTurn(): void
    {
        $provider = self::provider(self::script('simple-text.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());

        $stops = $stream->ofKind(TokenStop::class)->toArray();

        self::assertCount(1, $stops);
        self::assertInstanceOf(TokenStop::class, $stops[0]);
        self::assertSame(StopReason::EndOfTurn, $stops[0]->reason);
    }

    #[Test]
    public function functionCallFixtureEmitsEffectRequested(): void
    {
        $provider = self::provider(self::script('function-call.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $types = array_map(static fn ($c) => $c::class, $cues);

        self::assertContains(Requested::class, $types);
    }

    #[Test]
    public function functionCallFixtureStopReasonIsEndOfTurn(): void
    {
        $provider = self::provider(self::script('function-call.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());

        $stops = $stream->ofKind(TokenStop::class)->toArray();

        self::assertCount(1, $stops);
        self::assertInstanceOf(TokenStop::class, $stops[0]);
        self::assertSame(StopReason::EndOfTurn, $stops[0]->reason);
    }

    #[Test]
    public function thinkingFixtureEmitsThinkingChannelAndMessageChannel(): void
    {
        $provider = self::provider(self::script('thinking.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $deltas = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenDelta));
        $channels = array_map(static fn ($d) => $d->channel, $deltas);

        self::assertContains(Channel::Thinking, $channels);
        self::assertContains(Channel::Message, $channels);
    }

    #[Test]
    public function errorFixtureEmitsInvocationFailed(): void
    {
        $provider = self::provider(self::script('error.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $failed = array_values(array_filter($cues, static fn ($c) => $c instanceof Failed));

        self::assertCount(1, $failed);
        self::assertInstanceOf(Failed::class, $failed[0]);
        self::assertStringContainsString('Hephaestus', $failed[0]->reason);
        // Failed is terminal — no Completed must follow it.
        self::assertCount(0, array_filter($cues, static fn ($c) => $c instanceof Completed));
    }

    #[Test]
    public function errorMidStreamThenTransportCloseEmitsFailedExactlyOnceWithoutCompleted(): void
    {
        // error-mid-stream.sse: a candidates chunk fires first (stream starts), then an
        // error object arrives and transport closes. Contract: exactly one Failed, zero Completed.
        $provider = self::provider(self::script('error-mid-stream.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $failed = array_values(array_filter($cues, static fn ($c) => $c instanceof Failed));
        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof Completed));

        self::assertCount(1, $failed);
        self::assertCount(0, $completed);
    }

    #[Test]
    public function resolvedCueCarriesGeminiProvider(): void
    {
        $provider = self::provider(self::script('simple-text.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());

        $resolved = $stream->ofKind(Resolved::class)->toArray();

        self::assertCount(1, $resolved);
        self::assertInstanceOf(Resolved::class, $resolved[0]);
        self::assertSame('gemini', $resolved[0]->provider);
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

        $provider = new Provider(
            transport: $stub,
            apiKey: 'key_test',
            model: self::model(),
        );

        $stream = $provider->perform(self::invocation(), new Runtime());

        self::assertFalse($stub->called);

        iterator_to_array($stream);

        self::assertTrue($stub->called);
    }

    #[Test]
    public function capabilitiesReadFromModel(): void
    {
        $model = Model::of(
            name: 'gemini-vision',
            modelId: 'gemini-vision',
            aliases: ['vision'],
            capabilities: Capabilities::of(Capability::Vision),
        );
        $provider = new Provider(
            transport: new FakeTransport([]),
            apiKey: 'key_delphi',
            model: $model,
        );

        self::assertSame($model->capabilities, $provider->capabilities());
    }

    #[Test]
    public function cancellationMidStreamHaltsIteration(): void
    {
        $runtime = new Runtime();
        $provider = self::provider(self::script('simple-text.sse'));
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

    /** @param array<string, list<string>> $script */
    private static function provider(array $script, ?Options $options = null): Provider
    {
        return new Provider(
            transport: new FakeTransport($script),
            apiKey: 'key_demeter',
            model: self::model(),
            options: $options ?? new Options(),
        );
    }

    /**
     * Reads a fixture .sse file and returns a FakeTransport script map.
     * The key is "POST {url}" matching the Gemini streaming endpoint.
     *
     * @return array<string, list<string>>
     */
    private static function script(string $fixture): array
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/Provider/Gemini/' . $fixture;
        $raw = FixtureFile::read($path);

        $model = self::model();
        $apiKey = 'key_demeter';
        $url = 'https://generativelanguage.googleapis.com'
            . '/v1beta/models/'
            . rawurlencode($model->modelId)
            . ':streamGenerateContent?alt=sse&key='
            . urlencode($apiKey);

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
            id: 'inv_demeter',
            agentId: 'demeter',
            activityId: 'act_harvest',
            contextHash: str_repeat('e', 64),
            instructions: 'You guide the seasons.',
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::FileRead),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming(),
            dynamicContext: ['user_input' => 'What grows in the fields of Eleusis?'],
        );
    }

    private static function model(): Model
    {
        return Model::of(
            name: 'gemini-2.5-flash',
            modelId: 'gemini-2.5-flash',
            aliases: ['flash'],
            capabilities: Capabilities::of(Capability::Reasoning, Capability::ToolUse),
        );
    }
}
