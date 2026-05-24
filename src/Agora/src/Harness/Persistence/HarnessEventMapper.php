<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Phalanx\Agora\Harness\EventSource;
use Phalanx\Agora\Harness\HarnessEvent;

final class HarnessEventMapper
{
    /**
     * @param array<string, mixed> $row
     */
    public function fromRow(
        array $row,
        ?string $sessionId = null,
    ): HarnessEvent {
        return new HarnessEvent(
            id: self::recordId($row['id'] ?? null),
            sessionId: $sessionId ?? self::recordKey($row['session_id'] ?? null, 'agora_session'),
            turnId: self::nullableRecordKey($row['turn_id'] ?? null, 'agora_turn'),
            sequence: self::intField($row, 'sequence'),
            cueId: self::nullableString($row['cue_id'] ?? null),
            cueType: self::stringField($row, 'cue_type'),
            channel: self::nullableString($row['channel'] ?? null),
            source: EventSource::from(self::stringField($row, 'source')),
            payload: self::arrayField($row['payload'] ?? []),
            occurredAt: self::instant($row['occurred_at'] ?? null),
            receivedAt: self::instant($row['received_at'] ?? null),
        );
    }

    private static function instant(
        mixed $value,
    ): DateTimeImmutable {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value)) {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }

        throw new \UnexpectedValueException('Surreal event row instant was missing or invalid.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function intField(
        array $row,
        string $field,
    ): int {
        $value = $row[$field] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException("Surreal event row field {$field} was missing or invalid.");
        }

        return $value;
    }

    private static function nullableString(
        mixed $value,
    ): ?string {
        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function stringField(
        array $row,
        string $field,
    ): string {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Surreal event row field {$field} was missing or invalid.");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayField(
        mixed $value,
    ): array {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException('Surreal event payload was missing or invalid.');
        }

        return $value;
    }

    private static function nullableRecordKey(
        mixed $value,
        string $table,
    ): ?string {
        if ($value === null) {
            return null;
        }

        return self::recordKey($value, $table);
    }

    private static function recordId(
        mixed $value,
    ): string {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Surreal record id was missing or invalid.');
        }

        return $value;
    }

    private static function recordKey(
        mixed $value,
        string $table,
    ): string {
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Surreal {$table} record reference was missing or invalid.");
        }

        $prefix = $table . ':';
        if (!str_starts_with($value, $prefix)) {
            throw new \UnexpectedValueException("Surreal record reference {$value} does not belong to {$table}.");
        }

        $key = substr($value, strlen($prefix));
        if (str_starts_with($key, '`') && str_ends_with($key, '`')) {
            return substr($key, 1, -1);
        }

        return $key;
    }
}
