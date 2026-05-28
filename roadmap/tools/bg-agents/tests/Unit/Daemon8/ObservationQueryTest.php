<?php

declare(strict_types=1);

namespace BgAgents\Tests\Unit\Daemon8;

use BgAgents\Daemon8\ObservationQuery;
use PHPUnit\Framework\TestCase;

final class ObservationQueryTest extends TestCase
{
    public function test_default_emits_only_limit(): void
    {
        $q = new ObservationQuery();

        self::assertSame('limit=50', $q->toQueryString());
    }

    public function test_kinds_and_tags_join_with_commas(): void
    {
        $q = new ObservationQuery(
            kinds: ['custom', 'log'],
            tags: ['bg.memory', 'vega'],
        );

        $params = [];
        parse_str($q->toQueryString(), $params);

        self::assertSame('custom,log', $params['kinds']);
        self::assertSame('bg.memory,vega', $params['tags']);
        self::assertSame('50', $params['limit']);
    }

    public function test_limit_is_capped_at_500(): void
    {
        $q = new ObservationQuery(limit: 9999);

        self::assertStringContainsString('limit=500', $q->toQueryString());
    }

    public function test_optional_filters_are_omitted_when_null(): void
    {
        $q = new ObservationQuery(textMatch: null, correlationId: null, since: null, severityMin: null);
        $qs = $q->toQueryString();

        self::assertStringNotContainsString('text_match', $qs);
        self::assertStringNotContainsString('correlation_id', $qs);
        self::assertStringNotContainsString('since=', $qs);
        self::assertStringNotContainsString('severity_min', $qs);
    }

    public function test_full_query_round_trip(): void
    {
        $q = new ObservationQuery(
            kinds: ['custom'],
            tags: ['bg.bookkeeper.issue'],
            origins: ['app:bg-agents'],
            textMatch: 'duplicate',
            correlationId: 'trace-1',
            since: 12345,
            limit: 100,
            severityMin: 'warn',
            includeSystem: true,
        );

        $params = [];
        parse_str($q->toQueryString(), $params);

        self::assertSame('custom', $params['kinds']);
        self::assertSame('bg.bookkeeper.issue', $params['tags']);
        self::assertSame('app:bg-agents', $params['origins']);
        self::assertSame('duplicate', $params['text_match']);
        self::assertSame('trace-1', $params['correlation_id']);
        self::assertSame('12345', $params['since']);
        self::assertSame('100', $params['limit']);
        self::assertSame('warn', $params['severity_min']);
        self::assertSame('true', $params['include_system']);
    }
}
