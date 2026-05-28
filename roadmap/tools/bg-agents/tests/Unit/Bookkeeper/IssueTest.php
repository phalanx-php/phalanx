<?php

declare(strict_types=1);

namespace BgAgents\Tests\Unit\Bookkeeper;

use BgAgents\Bookkeeper\Issue;
use BgAgents\Bookkeeper\IssueKind;
use PHPUnit\Framework\TestCase;

final class IssueTest extends TestCase
{
    public function test_duplicate_factory(): void
    {
        $issue = Issue::duplicate('abc123', 100, 200);

        self::assertSame(IssueKind::Duplicate, $issue->kind);
        self::assertSame([100, 200], $issue->refs);
        self::assertStringContainsString('200', $issue->suggestion);
        self::assertStringContainsString('100', $issue->suggestion);
        self::assertStringContainsString('abc123', $issue->suggestion);
        self::assertStringStartsWith('dup-', $issue->id);
    }

    public function test_duplicate_id_is_stable_for_same_inputs(): void
    {
        $a = Issue::duplicate('abc', 1, 2);
        $b = Issue::duplicate('abc', 1, 2);

        self::assertSame($a->id, $b->id);
    }

    public function test_duplicate_id_differs_for_different_currents(): void
    {
        $a = Issue::duplicate('abc', 1, 2);
        $b = Issue::duplicate('abc', 1, 3);

        self::assertNotSame($a->id, $b->id);
    }
}
