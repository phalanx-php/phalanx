<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\Ollama;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Invocation\Completed;
use Phalanx\Panoply\Cue\Invocation\Failed;
use Phalanx\Panoply\Cue\Invocation\Started;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\Provider\Resolved;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Ollama\ChatOptions;
use Phalanx\Panoply\Provider\Ollama\ChatProvider;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Runtime\Sync\Runtime;
use Phalanx\Panoply\Transport\Fake\Transport as FakeTransport;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChatProviderTest extends TestCase
{
    #[Test]
    public function simpleFixtureEmitsExpectedCueTypes(): void
    {
        $provider = self::provider(self::script('chat-simple.ndjson'));
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
        $provider = self::provider(self::script('chat-simple.ndjson'));
        $stream = $provider->perform(self::invocation(), new Runtime());

        $transcript = '';
        foreach ($stream->tokens() as $cue) {
            if ($cue instanceof TokenDelta) {
                $transcript .= $cue->text;
            }
        }

        self::assertSame('Leonidas led the hoplites at Thermopylae.', $transcript);
    }

    #[Test]
    public function thinkingFixtureKeepsThinkingSeparateFromFinalAnswer(): void
    {
        $provider = self::provider(self::script('chat-thinking.ndjson'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $deltas = [];
        foreach ($cues as $cue) {
            if (!$cue instanceof TokenDelta) {
                continue;
            }

            $deltas[] = [$cue->channel, $cue->text];
        }

        self::assertSame([
            [Channel::Thinking, 'First, '],
            [Channel::Thinking, 'identify the pass. '],
            [Channel::Thinking, 'Then answer.'],
            [Channel::Message, 'Hold Thermopylae.'],
        ], $deltas);
    }

    #[Test]
    public function toolCallFixtureEmitsEffectRequested(): void
    {
        $provider = self::provider(self::script('chat-tool-call.ndjson'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $requested = array_values(array_filter($cues, static fn ($c) => $c instanceof Requested));

        self::assertCount(1, $requested);
        self::assertStringContainsString('search_agora', $requested[0]->summary);
    }

    #[Test]
    public function errorFixtureEmitsInvocationFailed(): void
    {
        $provider = self::provider(self::script('chat-error.ndjson'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $failed = array_values(array_filter($cues, static fn ($c) => $c instanceof Failed));

        self::assertCount(1, $failed);
        self::assertStringContainsString('olympus-7b', $failed[0]->reason);
        // Failed is terminal — no Completed must follow it.
        self::assertCount(0, array_filter($cues, static fn ($c) => $c instanceof Completed));
    }

    #[Test]
    public function errorMidStreamThenTransportCloseEmitsFailedExactlyOnceWithoutCompleted(): void
    {
        // chat-error-mid-stream.ndjson: a content delta fires first (stream starts), then an
        // error line arrives and transport closes. Contract: exactly one Failed, zero Completed.
        $provider = self::provider(self::script('chat-error-mid-stream.ndjson'));
        $stream = $provider->perform(self::invocation(), new Runtime());
        $cues = $stream->toArray();

        $failed = array_values(array_filter($cues, static fn ($c) => $c instanceof Failed));
        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof Completed));

        self::assertCount(1, $failed);
        self::assertCount(0, $completed);
    }

    #[Test]
    public function streamEndingWithoutWireTerminatorStillEmitsCompleted(): void
    {
        // A stream that ends before done:true (transport truncation / cancellation).
        // The defensive complete() wired in ChatProvider::perform() must emit exactly
        // one Completed — no duplicate, no missing.
        $provider = self::provider(self::script('truncated-start-only.ndjson'));
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
        $provider = self::provider(self::script('chat-simple.ndjson'));
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
    public function capabilitiesReadFromModel(): void
    {
        $model = Model::of(
            name: 'qwen2.5',
            modelId: 'qwen2.5',
            aliases: ['qwen'],
            capabilities: Capabilities::of(Capability::ToolUse),
        );
        $provider = new ChatProvider(
            transport: new FakeTransport([]),
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
                yield '{}' . "\n";
            }
        };

        $provider = new ChatProvider(
            transport: $stub,
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
        $path = dirname(__DIR__, 3) . '/Fixtures/Provider/Ollama/' . $fixture;
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException("Fixture not found: {$path}");
        }

        // Split NDJSON at newlines; each line is one chunk.
        $chunks = array_values(array_filter(
            array_map(
                static fn (string $line): string => $line . "\n",
                explode("\n", rtrim($raw, "\n")),
            ),
            static fn (string $s): bool => trim($s) !== '',
        ));

        return ['POST http://localhost:11434/api/chat' => $chunks];
    }

    /** @param array<string, list<string>> $script */
    private static function provider(array $script, ?ChatOptions $options = null): ChatProvider
    {
        return new ChatProvider(
            transport: new FakeTransport($script),
            model: self::model(),
            chatOptions: $options ?? new ChatOptions(),
        );
    }

    private static function invocation(): Invocation
    {
        return Invocation::of(
            id: 'inv_leonidas',
            agentId: 'leonidas',
            activityId: 'act_thermopylae',
            contextHash: str_repeat('i', 64),
            instructions: 'Rally the hoplites at the pass.',
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::FileRead),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::ToolUse),
            transport: TransportNeeds::new()->streaming(),
            dynamicContext: ['user_input' => 'What is the battle plan?'],
        );
    }

    private static function model(): Model
    {
        return Model::of(
            name: 'llama3.1',
            modelId: 'llama3.1',
            aliases: ['llama'],
            capabilities: Capabilities::of(Capability::ToolUse),
        );
    }
}
