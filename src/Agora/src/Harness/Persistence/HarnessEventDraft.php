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
}
