<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\Anthropic;

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
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Anthropic\MessagesOptions;
use Phalanx\Panoply\Provider\Anthropic\Provider;
use Phalanx\Panoply\Provider\Config\Model;
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
 */
final class ProviderTest extends TestCase
{
    #[Test]
    public function simpleMessageFixtureEmitsExpectedCueTypes(): void
    {
        $provider = self::provider(self::script('simple-message.sse'));
        $stream   = $provider->perform(self::invocation(), new Runtime());
        $cues     = $stream->toArray();

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
        $stream   = $provider->perform(self::invocation(), new Runtime());

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
        $stream   = $provider->perform(self::invocation(), new Runtime());

        $stops = $stream->ofKind(TokenStop::class)->toArray();

        self::assertCount(1, $stops);
        self::assertSame(StopReason::EndOfTurn, $stops[0]->reason);
    }

    #[Test]
    public function toolUseFixtureEmitsEffectRequestedAndArgumentsDeltas(): void
    {
        $provider = self::provider(self::script('tool-use.sse'));
        $stream   = $provider->perform(self::invocation(), new Runtime());
        $cues     = $stream->toArray();

        $types = array_map(static fn ($c) => $c::class, $cues);

        self::assertContains(Requested::class, $types);
        self::assertContains(ArgumentsDelta::class, $types);
    }

    #[Test]
    public function toolUseStopReasonIsToolUse(): void
    {
        $provider = self::provider(self::script('tool-use.sse'));
        $stream   = $provider->perform(self::invocation(), new Runtime());

        $stops = $stream->ofKind(TokenStop::class)->toArray();

        self::assertCount(1, $stops);
        self::assertSame(StopReason::ToolUse, $stops[0]->reason);
    }

    #[Test]
    public function errorFixtureEmitsInvocationFailed(): void
    {
        $provider = self::provider(self::script('error.sse'));
        $stream   = $provider->perform(self::invocation(), new Runtime());
        $cues     = $stream->toArray();

        $failed = array_values(array_filter($cues, static fn ($c) => $c instanceof Failed));

        self::assertCount(1, $failed);
        self::assertStringContainsString('Olympus', $failed[0]->reason);
    }

    #[Test]
    public function cancellationMidStreamHaltsIteration(): void
    {
        $runtime  = new Runtime();
        $provider = self::provider(self::script('simple-message.sse'));
        $stream   = $provider->perform(self::invocation(), $runtime);

        $count     = 0;
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
        } catch (\Phalanx\Panoply\Runtime\CancellationException) {
            $cancelled = true;
        }

        // We consumed at least 1 cue and the stream was cancelled.
        self::assertGreaterThanOrEqual(1, $count);
        self::assertTrue($cancelled);
        self::assertTrue($runtime->isCancelled());
    }

    #[Test]
    public function capabilitiesReadFromModel(): void
    {
        // Build a model with a deliberately distinct capability set so the
        // assertion proves delegation rather than a coincidental match.
        $model    = Model::of(
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
        $raw  = file_get_contents($path);

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
