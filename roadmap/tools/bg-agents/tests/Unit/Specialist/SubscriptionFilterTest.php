<?php

declare(strict_types=1);

namespace BgAgents\Tests\Unit\Specialist;

use BgAgents\Daemon8\ObservationRecord;
use BgAgents\Specialist\SubscriptionFilter;
use PHPUnit\Framework\TestCase;

final class SubscriptionFilterTest extends TestCase
{
    private function record(string $kind, array $tags, string $severity = 'info', ?string $originName = 'app:bg-agents'): ObservationRecord
    {
        return ObservationRecord::fromRow([
            'id' => 1,
            'kind' => $kind,
            'origin' => ['type' => 'application', 'name' => $originName],
            'data' => [],
            'severity' => $severity,
            'timestamp_ns' => 0,
            'tags' => $tags,
        ]);
    }

    public function test_empty_filter_matches_anything(): void
    {
        $filter = new SubscriptionFilter();

        self::assertTrue($filter->isEmpty());
        self::assertTrue($filter->matches($this->record('log', [])));
    }

    public function test_kinds_must_include(): void
    {
        $filter = new SubscriptionFilter(kinds: ['custom']);

        self::assertTrue($filter->matches($this->record('custom', [])));
        self::assertFalse($filter->matches($this->record('log', [])));
    }

    public function test_all_tags_required(): void
    {
        $filter = new SubscriptionFilter(tags: ['runtime', 'platform']);

        self::assertTrue($filter->matches($this->record('log', ['runtime', 'platform'])));
        self::assertFalse($filter->matches($this->record('log', ['runtime'])));
    }

    public function test_severity_threshold(): void
    {
        $filter = new SubscriptionFilter(severityMin: 'warn');

        self::assertTrue($filter->matches($this->record('log', [], 'warn')));
        self::assertTrue($filter->matches($this->record('log', [], 'error')));
        self::assertFalse($filter->matches($this->record('log', [], 'info')));
    }

    public function test_origin_prefix_match(): void
    {
        $filter = new SubscriptionFilter(origins: ['application:example']);

        self::assertTrue($filter->matches($this->record('log', [], 'info', 'example')));
        self::assertFalse($filter->matches($this->record('log', [], 'info', 'sentinel')));
    }

    public function test_from_array_handles_list_or_string(): void
    {
        $filter = SubscriptionFilter::fromArray(['kinds' => 'custom', 'tags' => ['a', 'b']]);

        self::assertSame(['custom'], $filter->kinds);
        self::assertSame(['a', 'b'], $filter->tags);
    }
}
