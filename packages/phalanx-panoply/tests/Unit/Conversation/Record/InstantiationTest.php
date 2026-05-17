<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Conversation\Record;

use Phalanx\Panoply\Conversation\Record;
use Phalanx\Panoply\Conversation\Record\Attachment;
use Phalanx\Panoply\Conversation\Record\Error;
use Phalanx\Panoply\Conversation\Record\FileSnapshot;
use Phalanx\Panoply\Conversation\Record\Message;
use Phalanx\Panoply\Conversation\Record\Metadata;
use Phalanx\Panoply\Conversation\Record\PermissionMode;
use Phalanx\Panoply\Conversation\Record\PermissionMode\Mode;
use Phalanx\Panoply\Conversation\Record\Sidechain;
use Phalanx\Panoply\Conversation\Record\ToolCall;
use Phalanx\Panoply\Conversation\Record\ToolResult;
use Phalanx\Panoply\Conversation\Record\Unknown;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every concrete Record subclass instantiates cleanly with valid args and
 * declares a unique stable `type` identifier.
 */
final class InstantiationTest extends TestCase
{
    #[Test]
    public function everyRecordSubclassInstantiates(): void
    {
        $records = self::allRecords();

        self::assertCount(10, $records);

        foreach ($records as $record) {
            self::assertInstanceOf(Record::class, $record);
        }
    }

    #[Test]
    public function typeIdentifiersAreUnique(): void
    {
        $types = array_map(static fn (Record $r): string => $r->type->value, self::allRecords());

        self::assertCount(count($types), array_unique($types), 'type identifiers must be unique across records');
    }

    #[Test]
    public function messageExposesExpectedFields(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $record = new Message(
            id: 'rec_1',
            sequence: 0,
            at: $at,
            role: 'user',
            text: 'How long did Leonidas hold Thermopylae?',
        );

        self::assertSame('user', $record->role);
        self::assertSame('How long did Leonidas hold Thermopylae?', $record->text);
        self::assertSame([], $record->attachments);
        self::assertSame('rec_1', $record->id);
        self::assertSame(0, $record->sequence);
    }

    #[Test]
    public function toolCallExposesExpectedFields(): void
    {
        $record = new ToolCall(
            id: 'rec_2',
            sequence: 1,
            at: new \DateTimeImmutable('2026-05-17T12:00:00Z'),
            callId: 'call_1',
            toolName: 'agora.search',
            arguments: ['q' => 'sparta', 'limit' => 10],
        );

        self::assertSame('call_1', $record->callId);
        self::assertSame('agora.search', $record->toolName);
        self::assertSame(['q' => 'sparta', 'limit' => 10], $record->arguments);
    }

    #[Test]
    public function sequenceIsNullable(): void
    {
        $record = new Message(
            id: 'rec_x',
            sequence: null,
            at: new \DateTimeImmutable('2026-05-17T12:00:00Z'),
            role: 'assistant',
            text: 'The battle lasted three days.',
        );

        self::assertNull($record->sequence);
    }

    /**
     * @return list<Record>
     */
    private static function allRecords(): array
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $base = ['id' => 'rec_x', 'sequence' => 1, 'at' => $at];

        return [
            new Message(...$base, role: 'user', text: 'How long was Leonidas at Thermopylae?'),
            new ToolCall(...$base, callId: 'call_1', toolName: 'agora.search', arguments: ['q' => 'sparta']),
            new ToolResult(...$base, callId: 'call_1', output: 'Three days.'),
            new Attachment(...$base, attachmentId: 'att_1', filename: 'hoplite.png', mime: 'image/png'),
            new FileSnapshot(
                ...$base,
                path: '/agora/plans.md',
                content: 'sparta wins',
                contentHash: str_repeat('a', 64),
            ),
            new PermissionMode(...$base, mode: Mode::Allow),
            new Sidechain(...$base, parentId: 'rec_p', branch: 'thinking'),
            new Metadata(...$base, key: 'model', value: 'claude-opus-4-7'),
            new Error(...$base, code: 'tool.timeout', message: 'The oracle did not respond.'),
            new Unknown(...$base, rawJson: '{"kind":"olympus.unknown","data":{}}'),
        ];
    }
}
