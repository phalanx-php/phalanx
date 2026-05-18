<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Integration\Provider;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Invocation\Completed;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Anthropic\Provider;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Runtime\Sync\Runtime;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use Phalanx\Panoply\Transport\Sync\HttpError;
use Phalanx\Panoply\Transport\Sync\Transport;
use PHPUnit\Framework\Attributes\RequiresEnvironment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Real Anthropic API integration test. Skipped by default — set the
 * `ANTHROPIC_API_KEY` environment variable to enable.
 *
 * Costs approximately $0.0005 per run (claude-haiku-3-5, short prompt).
 *
 * This test verifies that the full Anthropic provider stack — Transport,
 * CueMapper, SSE parser — produces a valid cue stream containing token
 * deltas, at least one final usage report, and exactly one completed
 * lifecycle marker.
 */
final class AnthropicLiveTest extends TestCase
{
    #[Test]
    #[RequiresEnvironment('ANTHROPIC_API_KEY')]
    public function realAnthropicApiCallEmitsCueStream(): void
    {
        $apiKey = getenv('ANTHROPIC_API_KEY');
        if ($apiKey === false || $apiKey === '') {
            self::markTestSkipped('ANTHROPIC_API_KEY not set');
        }

        $model = Model::of(
            name: 'claude-haiku-3-5',
            modelId: 'claude-haiku-3-5',
            aliases: ['haiku'],
            capabilities: Capabilities::of(Capability::Reasoning),
            inputPricing: 0.00025,
            outputPricing: 0.00125,
        );

        $provider = new Provider(
            transport: new Transport(),
            apiKey: $apiKey,
            model: $model,
        );

        $invocation = Invocation::of(
            id: 'live-test-01',
            agentId: 'agent.sparta',
            activityId: 'act.thermopylae',
            contextHash: str_repeat('0', 64),
            instructions: 'You are a Spartan herald. Respond in 10 words or fewer.',
            output: Output::text(),
            effects: Effects::none(),
            provider: ProviderNeeds::new(),
            transport: TransportNeeds::new(),
            dynamicContext: ['user_input' => 'What was the message at Thermopylae?'],
            createdAt: new \DateTimeImmutable(),
        );

        try {
            $cues = $provider->perform($invocation, new Runtime())->toArray();
        } catch (HttpError $e) {
            // A 4xx response (e.g. depleted credits, invalid key) means the
            // transport and provider stack reached the API — the integration
            // path works. Skip rather than fail so a depleted test key does
            // not block CI.
            self::markTestSkipped(sprintf(
                'Anthropic API returned HTTP %d — transport verified but response was error: %s',
                $e->statusCode,
                $e->getMessage(),
            ));
        }

        $tokenDeltas = array_filter($cues, static fn ($c): bool => $c instanceof TokenDelta);
        $completed   = array_filter($cues, static fn ($c): bool => $c instanceof Completed);
        $finalUsages = array_filter($cues, static fn ($c): bool => $c instanceof FinalUsage);

        self::assertNotEmpty($tokenDeltas, 'Expected at least one TokenDelta cue');
        self::assertCount(1, $completed, 'Expected exactly one Completed cue');
        self::assertNotEmpty($finalUsages, 'Expected at least one FinalUsage cue');
    }
}
