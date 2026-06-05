<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Provider\Anthropic;

use Phalanx\AiProviders\Artifact\Kind as ArtifactKind;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Cue\Effect\ArgumentsDelta;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Cue\Invocation\Completed;
use Phalanx\AiProviders\Cue\Invocation\Failed;
use Phalanx\AiProviders\Cue\Invocation\Started;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\AiProviders\Cue\Output\TokenStop;
use Phalanx\AiProviders\Cue\Provider\Resolved;
use Phalanx\AiProviders\Cue\StopReason;
use Phalanx\AiProviders\Effect\Kind as EffectKind;
use Phalanx\AiProviders\Effects;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Output;
use Phalanx\AiProviders\Provider\Anthropic\MessagesOptions;
use Phalanx\AiProviders\Provider\Anthropic\Provider;
use Phalanx\AiProviders\Provider\Config\Model;
use Phalanx\AiProviders\Provider\Needs as ProviderNeeds;
use Phalanx\AiProviders\Provider\Preference;
use Phalanx\AiProviders\Runtime\Sync\Runtime;
use Phalanx\AiProviders\Transport\Fake\Transport as FakeTransport;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end provider tests. Uses {@see FakeTransport} scripted with
 * recorded SSE fixture files — no live network calls.
 */
final class ProviderTest extends TestCase
{
    #[Test]
    public function simpleMessageFixtureEmitsExpectedCueTypes(): void
    {
        $provider = self::provider(self::script('simple-message.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $types = array_map(static fn ($c) => $c::class, $cues);

        self::assertContains(Resolved::class, $types);
        self::assertContains(Started::class, $types);
        self::assertContains(TokenDelta::class, $types);
        self::assertContains(TokenStop::class, $types);
        self::assertContains(Completed::class, $types);
    }

    #[Test]
    public function simpleMessageTranscriptAssembles(): void
    {
        $provider = self::provider(self::script('simple-message.sse'));
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
    public function simpleMessageStopReasonIsEndOfTurn(): void
    {
        $provider = self::provider(self::script('simple-message.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());

        $stops = $stream->ofKind(TokenStop::class)->toArray();

        self::assertCount(1, $stops);
        self::assertInstanceOf(TokenStop::class, $stops[0]);
        self::assertSame(StopReason::EndOfTurn, $stops[0]->reason);
    }

    #[Test]
    public function toolUseFixtureEmitsEffectRequestedAndArgumentsDeltas(): void
    {
        $provider = self::provider(self::script('tool-use.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $types = array_map(static fn ($c) => $c::class, $cues);

        self::assertContains(Requested::class, $types);
        self::assertContains(ArgumentsDelta::class, $types);
    }

    #[Test]
    public function toolUseStopReasonIsToolUse(): void
    {
        $provider = self::provider(self::script('tool-use.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());

        $stops = $stream->ofKind(TokenStop::class)->toArray();

        self::assertCount(1, $stops);
        self::assertInstanceOf(TokenStop::class, $stops[0]);
        self::assertSame(StopReason::ToolUse, $stops[0]->reason);
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
        self::assertStringContainsString('Olympus', $failed[0]->reason);
        // Failed is terminal — no Completed must follow it.
        self::assertCount(0, array_filter($cues, static fn ($c) => $c instanceof Completed));
    }

    #[Test]
    public function errorMidStreamThenTransportCloseEmitsFailedExactlyOnceWithoutCompleted(): void
    {
        // error.sse: message_start fires first (stream starts), then an error event
        // arrives and the transport closes. Contract: exactly one Failed, zero Completed.
        $provider = self::provider(self::script('error.sse'));
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
        $provider = self::provider(self::script('simple-message.sse'));
        $stream = $provider->perform(self::invocation(), $runtime);

        $count = 0;
        $cancelled = false;
        try {
            foreach ($stream as $cue) {
                $count++;
                if ($count === 1) {
                    // Cancel after first cue — subsequent throwIfCancelled in
                    // FakeTransport throws CancellationException.
                    $runtime->cancel();
                }
            }
        } catch (\Phalanx\AiProviders\Runtime\CancellationException) {
            $cancelled = true;
        }

        // We consumed at least 1 cue and the stream was cancelled.
        self::assertGreaterThanOrEqual(1, $count);
        self::assertTrue($cancelled);
        self::assertTrue($runtime->isCancelled());
    }

    #[Test]
    public function streamEndingWithoutWireTerminatorStillEmitsCompleted(): void
    {
        // A stream that ends before message_stop (transport truncation / cancellation).
        // The defensive complete() wired in Provider::perform() must emit exactly
        // one Completed — no duplicate, no missing.
        $provider = self::provider(self::script('truncated-start-only.sse'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof Completed));

        self::assertCount(1, $completed);
        // Partial output must be present — the truncated fixture emits a TokenDelta before cutting off.
        self::assertNotEmpty(array_filter($cues, static fn ($c) => $c instanceof TokenDelta));
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
        // Build a model with a deliberately distinct capability set so the
        // assertion proves delegation rather than a coincidental match.
        $model = Model::of(
            name: 'apollo-vision',
            modelId: 'apollo-vision',
            aliases: ['apollo'],
            capabilities: Capabilities::of(Capability::Vision),
        );
        $provider = new Provider(
            transport: new FakeTransport([]),
            apiKey: 'key_olympus',
            model: $model,
        );

        // Provider must expose the same Capabilities instance the model holds.
        self::assertSame($model->capabilities, $provider->capabilities());
    }

    /** @param array<string, list<string>> $script */
    private static function provider(array $script, ?MessagesOptions $options = null): Provider
    {
        return new Provider(
            transport: new FakeTransport($script),
            apiKey: 'key_sparta',
            model: self::model(),
            messagesOptions: $options ?? new MessagesOptions(),
        );
    }

    /**
     * Reads a fixture .sse file and returns a FakeTransport script map
     * keyed by "POST https://api.anthropic.com/v1/messages".
     *
     * The fixture is split into chunks at double-newlines so the Parser's
     * buffer accumulation is exercised, not just full-event parsing.
     *
     * @return array<string, list<string>>
     */
    private static function script(string $fixture): array
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/Provider/Anthropic/' . $fixture;
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException("Fixture not found: {$path}");
        }

        // Split at double-newlines to create realistic chunk boundaries.
        $chunks = array_values(array_filter(
            array_map(
                static fn (string $s): string => $s . "\n\n",
                explode("\n\n", rtrim($raw, "\n")),
            ),
            static fn (string $s): bool => trim($s) !== '',
        ));

        return ['POST https://api.anthropic.com/v1/messages' => $chunks];
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
            dynamicContext: ['user_input' => 'What is your battle plan, Achilles?'],
        );
    }

    private static function model(): Model
    {
        return Model::of(
            name: 'claude-opus-4-7',
            modelId: 'claude-opus-4-7',
            aliases: ['opus'],
            capabilities: Capabilities::of(Capability::Reasoning, Capability::ToolUse),
        );
    }
}
