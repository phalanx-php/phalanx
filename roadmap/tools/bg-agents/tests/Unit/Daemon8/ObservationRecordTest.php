<?php

declare(strict_types=1);

namespace BgAgents\Tests\Unit\Daemon8;

use BgAgents\Daemon8\ObservationRecord;
use PHPUnit\Framework\TestCase;

final class ObservationRecordTest extends TestCase
{
    public function test_decodes_custom_observation_with_bg_kind(): void
    {
        $row = [
            'id' => 42,
            'kind' => ['type' => 'custom', 'channel' => 'agent'],
            'origin' => ['type' => 'application', 'name' => 'bg-agents'],
            'data' => ['bg_kind' => 'bg.team.heartbeat', 'specialist_count' => 5],
            'severity' => 'info',
            'timestamp_ns' => 1714000000000000000,
            'tags' => ['bg-agents', 'heartbeat'],
            'session_id' => 'bg-agents-1234',
            'correlation_id' => null,
        ];

        $record = ObservationRecord::fromRow($row);

        self::assertSame(42, $record->id);
        self::assertSame('custom', $record->kindTag);
        self::assertSame('agent', $record->channel());
        self::assertSame('bg.team.heartbeat', $record->bgKind());
        self::assertSame('bg-agents-1234', $record->sessionId);
        self::assertNull($record->correlationId);
        self::assertSame(['bg-agents', 'heartbeat'], $record->tags);
    }

    public function test_decodes_string_kind(): void
    {
        $row = [
            'id' => 7,
            'kind' => 'log',
            'origin' => [],
            'data' => ['msg' => 'hello'],
            'severity' => 'debug',
            'timestamp_ns' => 0,
        ];

        $record = ObservationRecord::fromRow($row);

        self::assertSame('log', $record->kindTag);
        self::assertNull($record->channel());
        self::assertNull($record->bgKind());
    }

    public function test_filters_non_string_tags(): void
    {
        $row = [
            'id' => 1,
            'kind' => 'log',
            'origin' => [],
            'data' => [],
            'severity' => 'info',
            'timestamp_ns' => 0,
            'tags' => ['ok', 123, null, 'fine'],
        ];

        $record = ObservationRecord::fromRow($row);

        self::assertSame(['ok', 'fine'], $record->tags);
    }
}
