<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Cue;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Activity;
use Phalanx\Panoply\Cue\Artifact;
use Phalanx\Panoply\Cue\Effect;
use Phalanx\Panoply\Cue\Invocation;
use Phalanx\Panoply\Cue\Output;
use Phalanx\Panoply\Cue\Provider;
use Phalanx\Panoply\Cue\Runtime;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Cue\Usage;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Hash\Canonical;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every concrete cue subclass instantiates cleanly with valid args and
 * declares a unique stable `type` identifier.
 */
final class InstantiationTest extends TestCase
{
    #[Test]
    public function everyCueSubclassInstantiates(): void
    {
        $cues = $this->allCues();

        self::assertCount(31, $cues);

        foreach ($cues as $cue) {
            self::assertInstanceOf(Cue::class, $cue);
            self::assertSame('act_1', $cue->activityId);
            self::assertGreaterThanOrEqual(0, $cue->sequence);
        }
    }

    #[Test]
    public function typeIdentifiersAreUnique(): void
    {
        $types = array_map(static fn (Cue $c): string => $c->type, $this->allCues());

        self::assertCount(count($types), array_unique($types), 'type identifiers must be unique across cues');
    }

    #[Test]
    public function everyCueHashes(): void
    {
        foreach ($this->allCues() as $cue) {
            $hash = Canonical::of($cue);
            self::assertSame(64, strlen($hash), $cue::class . ' must produce 64-char hash');
        }
    }

    /**
     * @return list<Cue>
     */
    private function allCues(): array
    {
        $base = [
            'id' => 'cue_1',
            'sequence' => 0,
            'activityId' => 'act_1',
            'invocationId' => 'inv_1',
            'agentId' => 'investigator',
            'at' => new \DateTimeImmutable('2026-05-17T12:00:00Z'),
        ];

        return [
            // Activity (4)
            new Activity\Started(...$base),
            new Activity\Completed(...$base),
            new Activity\Failed(...$base, reason: 'boom', errorClass: \RuntimeException::class),
            new Activity\Cancelled(...$base, reason: 'user-interrupt'),

            // Invocation (4)
            new Invocation\Started(...$base),
            new Invocation\Completed(...$base, stopReason: StopReason::EndOfTurn),
            new Invocation\Failed(...$base, reason: 'provider-503'),
            new Invocation\Cancelled(...$base, reason: 'scope-cancelled'),

            // Provider (3)
            new Provider\Resolved(
                ...$base,
                provider: 'anthropic',
                model: 'claude-opus-4-7',
                reasonCode: 'agent-preference-met',
            ),
            new Provider\RateLimited(...$base, provider: 'anthropic', model: 'claude-opus-4-7', retryAfterSeconds: 30),
            new Provider\Retrying(...$base, provider: 'anthropic', attempt: 2, maxAttempts: 5, backoffMs: 1000),

            // Output (3)
            new Output\TokenDelta(...$base, text: 'hello'),
            new Output\TokenStop(...$base, reason: StopReason::EndOfTurn),
            new Output\StructuredDelta(...$base, jsonDelta: '{"k":"v"}'),

            // Effect (7)
            new Effect\Requested(...$base, effectId: 'eff_1', kind: EffectKind::FileRead, summary: 'read project root'),
            new Effect\ArgumentsDelta(...$base, effectId: 'eff_1', jsonDelta: '{"p":'),
            new Effect\Authorized(...$base, effectId: 'eff_1', grantId: 'grant_1'),
            new Effect\Denied(...$base, effectId: 'eff_1', reasonCodes: ['no-grant', 'hazard-high']),
            new Effect\Paused(...$base, effectId: 'eff_1', reason: 'awaiting-human'),
            new Effect\Executed(...$base, effectId: 'eff_1', durationMs: 42),
            new Effect\Failed(...$base, effectId: 'eff_1', reason: 'permission-denied'),

            // Artifact (3)
            new Artifact\Drafting(...$base, artifactId: 'art_1', kind: ArtifactKind::Thesis),
            new Artifact\Delta(...$base, artifactId: 'art_1', contentDelta: 'partial...'),
            new Artifact\Finalized(...$base, artifactId: 'art_1', contentHash: str_repeat('a', 64)),

            // Usage (2)
            new Usage\Delta(...$base, inputTokens: 100, outputTokens: 50),
            new Usage\FinalUsage(...$base, inputTokens: 800, outputTokens: 400, costUsd: 0.075),

            // Runtime (5)
            new Runtime\Notice(...$base, message: 'note'),
            new Runtime\Warning(...$base, message: 'careful'),
            new Runtime\Error(...$base, message: 'broke', errorClass: \RuntimeException::class),
            new Runtime\ClientConnected(...$base, clientId: 'cli_1', clientKind: 'theatron'),
            new Runtime\ClientDisconnected(...$base, clientId: 'cli_1', reason: 'graceful'),
        ];
    }
}
