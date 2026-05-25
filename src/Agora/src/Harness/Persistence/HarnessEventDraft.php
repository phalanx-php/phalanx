<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Phalanx\Agora\Harness\EventSource;
use Phalanx\Panoply\Cue;

final class HarnessEventDraft
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private(set) string $sessionId,
        private(set) ?string $turnId,
        private(set) ?string $cueId,
        private(set) string $cueType,
        private(set) ?string $channel,
        private(set) EventSource $source,
        private(set) array $payload,
        private(set) DateTimeImmutable $occurredAt,
        private(set) DateTimeImmutable $receivedAt,
        private(set) string $sourceKey,
    ) {
    }

    public static function fromCue(
        Cue $cue,
        string $sessionId,
        ?string $turnId = null,
        ?DateTimeImmutable $receivedAt = null,
    ): self {
        $canonical = $cue->toCanonical();
        $canonical['source_sequence'] = $cue->sequence;

        return new self(
            sessionId: $sessionId,
            turnId: $turnId,
            cueId: $cue->id,
            cueType: $cue->type,
            channel: self::channelFrom($canonical),
            source: EventSource::Panoply,
            payload: $canonical,
            occurredAt: $cue->at,
            receivedAt: $receivedAt ?? $cue->at,
            sourceKey: self::sourceKey(EventSource::Panoply, $cue->id, $cue->type, $turnId, $cue->at, $canonical),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function marker(
        string $sessionId,
        string $cueType,
        EventSource $source,
        DateTimeImmutable $occurredAt,
        array $payload = [],
        ?string $turnId = null,
        ?DateTimeImmutable $receivedAt = null,
    ): self {
        return new self(
            sessionId: $sessionId,
            turnId: $turnId,
            cueId: null,
            cueType: $cueType,
            channel: null,
            source: $source,
            payload: $payload,
            occurredAt: $occurredAt,
            receivedAt: $receivedAt ?? $occurredAt,
            sourceKey: self::sourceKey($source, null, $cueType, $turnId, $occurredAt, $payload),
        );
    }

    /** @return array<string, mixed> */
    public function toRecordData(): array
    {
        return [
            'cueid' => $this->cueId,
            'cuetype' => $this->cueType,
            'channel' => $this->channel,
            'source' => $this->source->value,
            'sourcekey' => $this->sourceKey,
            'payload' => $this->payload,
            'occurred' => self::formatInstant($this->occurredAt),
            'received' => self::formatInstant($this->receivedAt),
        ];
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
        DateTimeImmutable $instant,
    ): string {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function sourceKey(
        EventSource $source,
        ?string $cueId,
        string $cueType,
        ?string $turnId,
        DateTimeImmutable $occurredAt,
        array $payload,
    ): string {
        if ($cueId !== null) {
            return "{$source->value}:{$cueId}";
        }

        try {
            $encoded = json_encode([
                'source' => $source->value,
                'cue_type' => $cueType,
                'turn_id' => $turnId,
                'occurred_at' => self::formatInstant($occurredAt),
                'payload' => $payload,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("Failed to encode harness event source key: {$e->getMessage()}", previous: $e);
        }

        return "{$source->value}:" . hash('sha256', $encoded);
    }
}
