<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Conversation\Record;

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
use Phalanx\Panoply\Hash\Canonical;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HashTest extends TestCase
{
    #[Test]
    public function messageHashStableAcrossInstances(): void
    {
        $at = self::at();
        $a = new Message('rec_1', 0, $at, role: 'user', text: 'Leonidas held Thermopylae.');
        $b = new Message('rec_1', 0, $at, role: 'user', text: 'Leonidas held Thermopylae.');

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function messageHashDiffersWhenTextDiffers(): void
    {
        $at = self::at();
        $a = new Message('rec_1', 0, $at, role: 'user', text: 'sparta');
        $b = new Message('rec_1', 0, $at, role: 'user', text: 'marathon');

        self::assertNotSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function messageHashIs64CharHex(): void
    {
        $record = new Message('rec_1', 0, self::at(), role: 'assistant', text: 'agora');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Canonical::of($record));
    }

    #[Test]
    public function timestampTimezoneIndependentForBaseAtField(): void
    {
        $utc = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $athens = new \DateTimeImmutable('2026-05-17T15:00:00+03:00');

        $a = new Message('rec_1', 0, $utc, role: 'user', text: 'hoplite');
        $b = new Message('rec_1', 0, $athens, role: 'user', text: 'hoplite');

        self::assertSame(
            Canonical::of($a),
            Canonical::of($b),
            'records representing the same instant must hash identically regardless of source timezone',
        );
    }

    #[Test]
    public function toolCallHashStableAcrossInstances(): void
    {
        $at = self::at();
        $a = new ToolCall('rec_2', 1, $at, callId: 'call_1', toolName: 'agora.search', arguments: ['q' => 'olympus']);
        $b = new ToolCall('rec_2', 1, $at, callId: 'call_1', toolName: 'agora.search', arguments: ['q' => 'olympus']);

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function toolCallHashIs64CharHex(): void
    {
        $record = new ToolCall('rec_2', 1, self::at(), callId: 'c', toolName: 't', arguments: []);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Canonical::of($record));
    }

    #[Test]
    public function toolResultHashIs64CharHex(): void
    {
        $record = new ToolResult('rec_3', 2, self::at(), callId: 'call_1', output: 'pericles');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Canonical::of($record));
    }

    #[Test]
    public function toolResultIsErrorChangesHash(): void
    {
        $at = self::at();
        $ok = new ToolResult('rec_3', 2, $at, callId: 'call_1', output: 'ok', isError: false);
        $err = new ToolResult('rec_3', 2, $at, callId: 'call_1', output: 'ok', isError: true);

        self::assertNotSame(Canonical::of($ok), Canonical::of($err));
    }

    #[Test]
    public function attachmentHashIs64CharHex(): void
    {
        $record = new Attachment(
            'rec_4',
            3,
            self::at(),
            attachmentId: 'att_1',
            filename: 'aspis.png',
            mime: 'image/png',
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Canonical::of($record));
    }

    #[Test]
    public function fileSnapshotHashIs64CharHex(): void
    {
        $record = new FileSnapshot(
            'rec_5',
            4,
            self::at(),
            path: '/agora/plan.md',
            content: 'march',
            contentHash: str_repeat('b', 64),
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Canonical::of($record));
    }

    #[Test]
    public function permissionModeHashIs64CharHex(): void
    {
        $record = new PermissionMode('rec_6', 5, self::at(), mode: Mode::Allow);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Canonical::of($record));
    }

    #[Test]
    public function permissionModeDifferentModesHashDifferently(): void
    {
        $at = self::at();
        $allow = new PermissionMode('rec_6', 5, $at, mode: Mode::Allow);
        $deny = new PermissionMode('rec_6', 5, $at, mode: Mode::Deny);

        self::assertNotSame(Canonical::of($allow), Canonical::of($deny));
    }

    #[Test]
    public function sidechainHashIs64CharHex(): void
    {
        $record = new Sidechain('rec_7', 6, self::at(), parentId: 'rec_p', branch: 'thinking');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Canonical::of($record));
    }

    #[Test]
    public function metadataHashIs64CharHex(): void
    {
        $record = new Metadata('rec_8', 7, self::at(), key: 'cost_center', value: 'agora');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Canonical::of($record));
    }

    #[Test]
    public function errorHashIs64CharHex(): void
    {
        $record = new Error('rec_9', 8, self::at(), code: 'oracle.timeout', message: 'The oracle did not respond.');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Canonical::of($record));
    }

    #[Test]
    public function unknownHashIs64CharHex(): void
    {
        $record = new Unknown('rec_10', 9, self::at(), rawJson: '{}');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Canonical::of($record));
    }

    #[Test]
    public function differentSubclassesSameBaseFieldsHashDifferently(): void
    {
        $at = self::at();
        $msg = new Message('rec_1', 0, $at, role: 'user', text: 'thermopylae');
        $err = new Error('rec_1', 0, $at, code: 'e', message: 'thermopylae');

        // Same id/sequence/at but different type → different hash
        self::assertNotSame(Canonical::of($msg), Canonical::of($err));
    }

    private static function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-05-17T12:00:00Z');
    }
}
