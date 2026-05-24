<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Projection;

use Phalanx\Agora\Harness\EventSource;
use Phalanx\Agora\Harness\HarnessEvent;
use Phalanx\Agora\Harness\ProjectionKind;
use Phalanx\Panoply\Hash\Canonical;

final class RuntimeProjection extends EventProjection
{
    /**
     * @param array<string, array<string, mixed>> $effects
     * @param array<string, array<string, mixed>> $effectLogs
     * @param list<array<string, mixed>> $runtimeEvents
     */
    public function __construct(
        string $sessionId,
        int $eventSequence = 0,
        private(set) array $effects = [],
        private(set) array $effectLogs = [],
        private(set) array $runtimeEvents = [],
    ) {
        parent::__construct($sessionId, $eventSequence);
    }

    public function kind(): ProjectionKind
    {
        return ProjectionKind::Runtime;
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return [
            'session_id' => $this->sessionId,
            'event_sequence' => $this->eventSequence,
            'effects' => $this->effects,
            'effect_logs' => $this->effectLogs,
            'runtime_events' => $this->runtimeEvents,
        ];
    }

    protected function applyEvent(
        HarnessEvent $event,
    ): void {
        if ($event->source === EventSource::Athena && $event->cueType === 'athena.effect_log') {
            $this->recordAthenaEffect($event);

            return;
        }

        if (str_starts_with($event->cueType, 'cue.effect.')) {
            $this->recordPanoplyEffect($event);

            return;
        }

        if (str_starts_with($event->cueType, 'cue.runtime.')) {
            $this->runtimeEvents[] = [
                'event_id' => $event->id,
                'cue_type' => $event->cueType,
                'payload' => $event->payload,
            ];
        }
    }

    /**
     * @param array<string, mixed> $effect
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function requestedEffect(
        array $effect,
        array $payload,
    ): array {
        $arguments = $payload['arguments'] ?? [];
        $effect['status'] = 'requested';
        $effect['kind'] = $payload['kind'] ?? null;
        $effect['summary'] = $payload['summary'] ?? null;
        $effect['arguments'] = is_array($arguments) ? $arguments : [];
        $effect['arguments_hash'] = Canonical::of($effect['arguments']);
        $effect['requires_approval'] = $payload['requires_approval'] ?? false;

        return $effect;
    }

    /**
     * @param array<string, mixed> $effect
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function authorizedEffect(
        array $effect,
        array $payload,
    ): array {
        $effect['status'] = 'authorized';
        $effect['grant_id'] = $payload['grant_id'] ?? null;

        return $effect;
    }

    /**
     * @param array<string, mixed> $effect
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function argumentsDeltaEffect(
        array $effect,
        array $payload,
    ): array {
        $deltas = $effect['argument_deltas'] ?? [];
        if (!is_array($deltas)) {
            $deltas = [];
        }

        $jsonDelta = $payload['json_delta'] ?? null;
        if (is_string($jsonDelta)) {
            $deltas[] = $jsonDelta;
        }

        $effect['argument_deltas'] = $deltas;

        return $effect;
    }

    /**
     * @param array<string, mixed> $effect
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function executedEffect(
        array $effect,
        array $payload,
    ): array {
        $effect['status'] = 'executed';
        $effect['duration_ms'] = $payload['duration_ms'] ?? null;
        $effect['result_digest'] = $payload['result_digest'] ?? null;

        return $effect;
    }

    /**
     * @param array<string, mixed> $effect
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function failedEffect(
        array $effect,
        array $payload,
    ): array {
        $effect['status'] = 'failed';
        $effect['reason'] = $payload['reason'] ?? null;
        $effect['error_class'] = $payload['error_class'] ?? null;

        return $effect;
    }

    /**
     * @param array<string, mixed> $effect
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function statusEffect(
        array $effect,
        string $status,
        array $payload,
    ): array {
        $effect['status'] = $status;
        $effect['reason'] = $payload['reason'] ?? null;
        $effect['reason_codes'] = $payload['reason_codes'] ?? null;

        return $effect;
    }

    /** @return array<string, mixed> */
    private static function cuePayload(
        HarnessEvent $event,
    ): array {
        $payload = $event->payload['payload'] ?? [];

        return is_array($payload) ? $payload : [];
    }

    private function recordAthenaEffect(
        HarnessEvent $event,
    ): void {
        $recordId = $event->payload['record_id'] ?? null;
        if (!is_string($recordId)) {
            return;
        }

        $this->effectLogs[$recordId] = [
            'record_id' => $recordId,
            'kind' => $event->payload['kind'] ?? null,
            'tool_name' => $event->payload['tool_name'] ?? null,
            'args_hash' => $event->payload['args_hash'] ?? null,
            'resolution' => $event->payload['resolution'] ?? null,
            'outcome' => $event->payload['outcome'] ?? null,
            'event_id' => $event->id,
        ];
    }

    private function recordPanoplyEffect(
        HarnessEvent $event,
    ): void {
        $payload = self::cuePayload($event);
        $effectId = $payload['effect_id'] ?? null;
        if (!is_string($effectId)) {
            return;
        }

        $effect = $this->effects[$effectId] ?? [
            'effect_id' => $effectId,
            'events' => [],
            'status' => 'requested',
        ];

        $effect['events'][] = $event->cueType;
        $effect['event_id'] = $event->id;

        $this->effects[$effectId] = match ($event->cueType) {
            'cue.effect.requested' => self::requestedEffect($effect, $payload),
            'cue.effect.authorized' => self::authorizedEffect($effect, $payload),
            'cue.effect.arguments_delta' => self::argumentsDeltaEffect($effect, $payload),
            'cue.effect.denied' => self::statusEffect($effect, 'denied', $payload),
            'cue.effect.paused' => self::statusEffect($effect, 'paused', $payload),
            'cue.effect.executed' => self::executedEffect($effect, $payload),
            'cue.effect.failed' => self::failedEffect($effect, $payload),
            default => $effect,
        };
    }
}
