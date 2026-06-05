<?php

declare(strict_types=1);

namespace Phalanx\Demos\Kit;

use Phalanx\AiProviders\Cue\Output\Channel;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\AiProviders\Cue\Output\TokenStop;
use Phalanx\AiProviders\Cue\StopReason;

/**
 * Convenience factory for scripted Cue sequences used with
 * {@see DemoProvider::ollamaOrFake()}. Builds the minimal token stream
 * that the fake provider replays when Ollama is down.
 *
 * Final — deterministic script contract; no extension points needed.
 */
final class FakeCueScript
{
    private function __construct()
    {
    }

    /**
     * Build a minimal token-stream script for the fake provider.
     *
     * Produces: N×TokenDelta (one per word) + TokenStop(EndOfTurn).
     * The activityId, invocationId, and agentId are placeholders; the
     * Fake\Provider ignores them — only the Cue types matter for the
     * demo's streaming assertions.
     *
     * @return list<\Phalanx\AiProviders\Cue>
     */
    public static function tokens(
        string $text,
        string $activityId = 'demo-activity',
        string $agentId = 'demo-agent',
    ): array {
        $at = new \DateTimeImmutable();
        $cues = [];
        $seq = 1;

        foreach (explode(' ', $text) as $word) {
            $cues[] = new TokenDelta(
                id: sprintf('cue_%d', $seq),
                sequence: $seq,
                activityId: $activityId,
                invocationId: null,
                agentId: $agentId,
                at: $at,
                text: ($seq === 1 ? '' : ' ') . $word,
                channel: Channel::Message,
            );
            $seq++;
        }

        $cues[] = new TokenStop(
            id: sprintf('cue_%d', $seq),
            sequence: $seq,
            activityId: $activityId,
            invocationId: null,
            agentId: $agentId,
            at: $at,
            reason: StopReason::EndOfTurn,
            channel: Channel::Message,
        );

        return $cues;
    }
}
