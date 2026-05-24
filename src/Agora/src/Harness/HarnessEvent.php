<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness;

use Phalanx\Athena\Persistence\EffectLogRecord;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Hash\Canonicalizable;

final class HarnessEvent implements Canonicalizable
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private(set) string $id,
        private(set) string $sessionId,
        private(set) ?string $turnId,
        private(set) int $sequence,
        private(set) ?string $cueId,
        private(set) string $cueType,
        private(set) ?string $channel,
        private(set) EventSource $source,
        private(set) array $payload,
        private(set) \DateTimeImmutable $occurredAt,
        private(set) \DateTimeImmutable $receivedAt,
    ) {
        if ($this->sequence < 1) {
            throw new \InvalidArgumentException('Harness event sequence must be greater than zero.');
        }
    }

    public static function fromCue(
        Cue $cue,
        string $sessionId,
        ?string $turnId = null,
        ?\DateTimeImmutable $receivedAt = null,
        ?string $id = null,
    ): self {
        $canonical = $cue->toCanonical();

        return new self(
            id: $id ?? self::eventId($sessionId, $cue->sequence),
            sessionId: $sessionId,
            turnId: $turnId,
            sequence: $cue->sequence,
            cueId: $cue->id,
            cueType: $cue->type,
            channel: self::channelFrom($canonical),
            source: EventSource::Panoply,
            payload: $canonical,
            occurredAt: $cue->at,
            receivedAt: $receivedAt ?? $cue->at,
        );
    }

    public static function fromAthenaEffect(
        EffectLogRecord $record,
        string $sessionId,
        int $sequence,
        ?string $turnId = null,
        ?\DateTimeImmutable $receivedAt = null,
        ?string $id = null,
    ): self {
        return new self(
            id: $id ?? self::eventId($sessionId, $sequence),
            sessionId: $sessionId,
            turnId: $turnId,
            sequence: $sequence,
            cueId: $record->id,
            cueType: 'athena.effect_log',
            channel: null,
            source: EventSource::Athena,
            payload: [
                'record_id' => $record->id,
                'invocation_id' => $record->invocationId,
                'kind' => $record->kind,
                'tool_name' => $record->toolName,
                'args_hash' => $record->argsHash,
                'resolution' => $record->resolution->value,
                'outcome' => $record->outcome,
                'at' => self::formatInstant($record->at),
            ],
            occurredAt: $record->at,
            receivedAt: $receivedAt ?? $record->at,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function marker(
        string $id,
        string $sessionId,
        int $sequence,
        string $cueType,
        EventSource $source,
        array $payload = [],
        ?string $turnId = null,
        ?\DateTimeImmutable $occurredAt = null,
        ?\DateTimeImmutable $receivedAt = null,
    ): self {
        $at = $occurredAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new self(
            id: $id,
            sessionId: $sessionId,
            turnId: $turnId,
            sequence: $sequence,
            cueId: null,
            cueType: $cueType,
            channel: null,
            source: $source,
            payload: $payload,
            occurredAt: $at,
            receivedAt: $receivedAt ?? $at,
        );
    }

    /** @return array<string, mixed> */
    public function toCanonical(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->sessionId,
            'turn_id' => $this->turnId,
            'sequence' => $this->sequence,
            'cue_id' => $this->cueId,
            'cue_type' => $this->cueType,
            'channel' => $this->channel,
            'source' => $this->source->value,
            'payload' => $this->payload,
            'occurred_at' => self::formatInstant($this->occurredAt),
            'received_at' => self::formatInstant($this->receivedAt),
        ];
    }

    private static function eventId(
        string $sessionId,
        int $sequence,
    ): string {
        return sprintf('agora.event.%s.%d', $sessionId, $sequence);
    }

    /**
     * @param array<string, mixed> $canonical
     */
    private static function channelFrom(
        array $canonical,
    ): ?string {
        $payload = $canonical['payload'] ?? null;
        if (!is_array($payload)) {
            return null;
        }

        $channel = $payload['channel'] ?? null;

        return is_string($channel) ? $channel : null;
    }

    private static function formatInstant(
        \DateTimeImmutable $instant,
    ): string {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
