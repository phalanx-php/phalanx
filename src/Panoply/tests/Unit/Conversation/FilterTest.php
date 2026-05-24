<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Conversation;

use Phalanx\Panoply\Conversation\Filter;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Conversation\Record;
use Phalanx\Panoply\Conversation\Record\Attachment;
use Phalanx\Panoply\Conversation\Record\Error;
use Phalanx\Panoply\Conversation\Record\Message;
use Phalanx\Panoply\Conversation\Record\Metadata;
use Phalanx\Panoply\Conversation\Record\PermissionMode;
use Phalanx\Panoply\Conversation\Record\PermissionMode\Mode as PermissionModeMode;
use Phalanx\Panoply\Conversation\Record\ToolCall;
use Phalanx\Panoply\Conversation\Record\ToolResult;
use Phalanx\Panoply\Conversation\RecordType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterTest extends TestCase
{
    #[Test]
    public function byTypeReturnsStaticClosure(): void
    {
        $closure = Filter::byType(RecordType::Message);

        self::assertTrue(new \ReflectionFunction($closure)->isStatic());
    }

    #[Test]
    public function byRoleReturnsStaticClosure(): void
    {
        $closure = Filter::byRole('user');

        self::assertTrue(new \ReflectionFunction($closure)->isStatic());
    }

    #[Test]
    public function sinceTimeReturnsStaticClosure(): void
    {
        $closure = Filter::sinceTime(new \DateTimeImmutable('2026-05-17T12:00:00Z'));

        self::assertTrue(new \ReflectionFunction($closure)->isStatic());
    }

    #[Test]
    public function untilTimeReturnsStaticClosure(): void
    {
        $closure = Filter::untilTime(new \DateTimeImmutable('2026-05-17T12:00:00Z'));

        self::assertTrue(new \ReflectionFunction($closure)->isStatic());
    }

    #[Test]
    public function byTypeMatchesCorrectType(): void
    {
        $filter = Filter::byType(RecordType::Message);
        $message = self::fixture();
        $tool = new ToolCall('r', 0, new \DateTimeImmutable(), callId: 'c', toolName: 't', arguments: []);

        self::assertTrue($filter($message));
        self::assertFalse($filter($tool));
    }

    #[Test]
    public function byRoleMatchesMessageRole(): void
    {
        $filter = Filter::byRole('user');
        $user = new Message('r', 0, new \DateTimeImmutable(), role: 'user', text: 'agora');
        $asst = new Message('r', 0, new \DateTimeImmutable(), role: 'assistant', text: 'olympus');

        self::assertTrue($filter($user));
        self::assertFalse($filter($asst));
    }

    #[Test]
    public function byRoleReturnsFalseForNonMessageRecord(): void
    {
        $filter = Filter::byRole('user');
        $err = new Error('r', 0, new \DateTimeImmutable(), code: 'e', message: 'm');

        self::assertFalse($filter($err));
    }

    #[Test]
    public function sinceTimeIncludesAtAndAfterThreshold(): void
    {
        $threshold = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $filter = Filter::sinceTime($threshold);

        $before = new Message('r', 0, new \DateTimeImmutable('2026-05-17T11:59:59Z'), role: 'user', text: 'sparta');
        $exact = new Message('r', 0, $threshold, role: 'user', text: 'sparta');
        $after = new Message('r', 0, new \DateTimeImmutable('2026-05-17T12:00:01Z'), role: 'user', text: 'sparta');

        self::assertFalse($filter($before));
        self::assertTrue($filter($exact));
        self::assertTrue($filter($after));
    }

    #[Test]
    public function untilTimeExcludesThresholdAndAfter(): void
    {
        $threshold = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $filter = Filter::untilTime($threshold);

        $before = new Message('r', 0, new \DateTimeImmutable('2026-05-17T11:59:59Z'), role: 'user', text: 'marathon');
        $exact = new Message('r', 0, $threshold, role: 'user', text: 'marathon');
        $after = new Message('r', 0, new \DateTimeImmutable('2026-05-17T12:00:01Z'), role: 'user', text: 'marathon');

        self::assertTrue($filter($before));
        self::assertFalse($filter($exact));
        self::assertFalse($filter($after));
    }

    #[Test]
    public function byRoleRejectsNonMessageRecordsAcrossTypes(): void
    {
        $filter = Filter::byRole('user');

        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $nonMessages = [
            new ToolCall('tc_1', 1, $at, callId: 'c1', toolName: 'agora.search', arguments: []),
            new ToolResult('tr_1', 2, $at, callId: 'c1', output: 'sparta'),
            new Attachment('at_1', 3, $at, attachmentId: 'a1', filename: 'shield.txt', mime: 'text/plain'),
            new PermissionMode('pm_1', 4, $at, mode: PermissionModeMode::Allow),
            new Metadata('md_1', 5, $at, key: 'k', value: 'v'),
        ];

        foreach ($nonMessages as $record) {
            self::assertFalse($filter($record), $record::class . ' should not match byRole');
        }
    }

    #[Test]
    public function logWhereByTypeReturnsOnlyMatchingRecords(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $records = [
            new Message('m1', 1, $at, role: 'user', text: 'apollo speaks'),
            new ToolCall('tc1', 2, $at, callId: 'c1', toolName: 'agora.search', arguments: []),
            new Message('m2', 3, $at, role: 'assistant', text: 'odysseus replies'),
            new Error('e1', 4, $at, code: 'parse-failed', message: 'malformed'),
        ];

        $log = Log::from($records);
        $messages = $log->where(Filter::byType(RecordType::Message))->toArray();

        self::assertCount(2, $messages);
        foreach ($messages as $record) {
            self::assertInstanceOf(Message::class, $record);
        }
    }

    private static function fixture(): Record
    {
        return new Message('r', 0, new \DateTimeImmutable('2026-05-17T12:00:00Z'), role: 'user', text: 'thermopylae');
    }
}
