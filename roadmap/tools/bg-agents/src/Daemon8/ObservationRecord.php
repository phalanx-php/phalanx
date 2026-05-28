<?php

declare(strict_types=1);

namespace BgAgents\Daemon8;

/**
 * Decoded observation row from /api/observe.
 *
 * Keeps original raw payload alongside the normalized fields so consumers can
 * dig into kind-specific fields (sql, http_exchange.duration_ms, custom.channel)
 * without an extra fetch.
 */
final readonly class ObservationRecord
{
    /**
     * @param array<string, mixed>|string $kind
     * @param array<string, mixed> $origin
     * @param array<string, mixed> $data
     * @param list<string> $tags
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public int $id,
        public array|string $kind,
        public string $kindTag,
        public array $origin,
        public array $data,
        public string $severity,
        public int $timestampNs,
        public array $tags,
        public ?string $sessionId,
        public ?string $correlationId,
        public array $raw,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $kind = $row['kind'] ?? 'log';
        $kindTag = is_array($kind) ? (string) ($kind['type'] ?? 'log') : (string) $kind;

        $origin = $row['origin'] ?? [];
        if (!is_array($origin)) {
            $origin = [];
        }

        $data = $row['data'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }

        $tags = $row['tags'] ?? [];
        $tags = is_array($tags) ? array_values(array_filter($tags, is_string(...))) : [];

        return new self(
            id: (int) ($row['id'] ?? 0),
            kind: is_array($kind) ? $kind : (string) $kind,
            kindTag: $kindTag,
            origin: $origin,
            data: $data,
            severity: (string) ($row['severity'] ?? 'info'),
            timestampNs: (int) ($row['timestamp_ns'] ?? 0),
            tags: $tags,
            sessionId: isset($row['session_id']) && is_string($row['session_id']) ? $row['session_id'] : null,
            correlationId: isset($row['correlation_id']) && is_string($row['correlation_id']) ? $row['correlation_id'] : null,
            raw: $row,
        );
    }

    public function bgKind(): ?string
    {
        $bgKind = $this->data['bg_kind'] ?? ($this->data['payload']['bg_kind'] ?? null);

        return is_string($bgKind) ? $bgKind : null;
    }

    public function channel(): ?string
    {
        if (is_array($this->kind) && isset($this->kind['channel']) && is_string($this->kind['channel'])) {
            return $this->kind['channel'];
        }

        return null;
    }
}
