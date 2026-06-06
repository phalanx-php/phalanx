<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Conversation\Record;

use Phalanx\AiProviders\Conversation\Record\Attachment;
use Phalanx\AiProviders\Conversation\Record\Error;
use Phalanx\AiProviders\Conversation\Record\FileSnapshot;
use Phalanx\AiProviders\Conversation\Record\Message;
use Phalanx\AiProviders\Conversation\Record\Metadata;
use Phalanx\AiProviders\Conversation\Record\PermissionMode;
use Phalanx\AiProviders\Conversation\Record\PermissionMode\Mode;
use Phalanx\AiProviders\Conversation\Record\Sidechain;
use Phalanx\AiProviders\Conversation\Record\ToolCall;
use Phalanx\AiProviders\Conversation\Record\ToolResult;
use Phalanx\AiProviders\Conversation\Record\Unknown;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies `toCanonical()` shape for every Record subclass: the `payload`
 * key must be present and contain the subclass-specific fields.
 */
final class PayloadTest extends TestCase
{
    #[Test]
    public function messagePayloadShape(): void
    {
        $record = new Message('r', 0, self::at(), role: 'user', text: 'agora', attachments: ['att_1']);
        $canonical = $record->toCanonical();

        self::assertArrayHasKey('payload', $canonical);
        self::assertSame('user', $canonical['payload']['role']);
        self::assertSame('agora', $canonical['payload']['text']);
        self::assertSame(['att_1'], $canonical['payload']['attachments']);
    }

    #[Test]
    public function messageCanonicalContainsBaseFields(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00.000000Z');
        $record = new Message('rec_x', 3, $at, role: 'assistant', text: 'sparta');
        $canonical = $record->toCanonical();

        self::assertSame('record.message', $canonical['type']);
        self::assertSame('rec_x', $canonical['id']);
        self::assertSame(3, $canonical['sequence']);
        self::assertStringEndsWith('Z', $canonical['at']);
    }

    #[Test]
    public function toolCallPayloadShape(): void
    {
        $record = new ToolCall(
            'r',
            0,
            self::at(),
            callId: 'c1',
            toolName: 'agora.search',
            arguments: ['q' => 'hoplite'],
        );
        $payload = $record->toCanonical()['payload'];

        self::assertSame('c1', $payload['call_id']);
        self::assertSame('agora.search', $payload['tool_name']);
        self::assertSame(['q' => 'hoplite'], $payload['arguments']);
    }

    #[Test]
    public function toolResultPayloadShape(): void
    {
        $record = new ToolResult('r', 0, self::at(), callId: 'c1', output: 'olympus', isError: true);
        $payload = $record->toCanonical()['payload'];

        self::assertSame('c1', $payload['call_id']);
        self::assertSame('olympus', $payload['output']);
        self::assertTrue($payload['is_error']);
    }

    #[Test]
    public function attachmentPayloadShape(): void
    {
        $record = new Attachment(
            'r',
            0,
            self::at(),
            attachmentId: 'att_1',
            filename: 'doru.png',
            mime: 'image/png',
            size: 4096,
            contentHash: str_repeat('c', 64),
        );
        $payload = $record->toCanonical()['payload'];

        self::assertSame('att_1', $payload['attachment_id']);
        self::assertSame('doru.png', $payload['filename']);
        self::assertSame('image/png', $payload['mime']);
        self::assertSame(4096, $payload['size']);
        self::assertSame(str_repeat('c', 64), $payload['content_hash']);
    }

    #[Test]
    public function attachmentNullableFieldsSerializedAsNull(): void
    {
        $record = new Attachment(
            'r',
            0,
            self::at(),
            attachmentId: 'att_2',
            filename: 'aspis.jpg',
            mime: 'image/jpeg',
        );
        $payload = $record->toCanonical()['payload'];

        self::assertNull($payload['size']);
        self::assertNull($payload['content_hash']);
    }

    #[Test]
    public function fileSnapshotPayloadShape(): void
    {
        $record = new FileSnapshot(
            'r',
            0,
            self::at(),
            path: '/agora/strategy.md',
            content: 'march on marathon',
            contentHash: str_repeat('d', 64),
        );
        $payload = $record->toCanonical()['payload'];

        self::assertSame('/agora/strategy.md', $payload['path']);
        self::assertSame('march on marathon', $payload['content']);
        self::assertSame(str_repeat('d', 64), $payload['content_hash']);
    }

    #[Test]
    public function permissionModePayloadShape(): void
    {
        $record = new PermissionMode('r', 0, self::at(), mode: Mode::Ask, permissionScope: 'bash:*');
        $payload = $record->toCanonical()['payload'];

        self::assertSame('ask', $payload['mode']);
        self::assertSame('bash:*', $payload['scope']);
    }

    #[Test]
    public function permissionModeNullScopeSerializedAsNull(): void
    {
        $record = new PermissionMode('r', 0, self::at(), mode: Mode::Deny);
        $payload = $record->toCanonical()['payload'];

        self::assertNull($payload['scope']);
    }

    #[Test]
    public function sidechainPayloadShape(): void
    {
        $record = new Sidechain(
            'r',
            0,
            self::at(),
            parentId: 'rec_p',
            branch: 'thinking',
            summary: 'Achilles wins.',
        );
        $payload = $record->toCanonical()['payload'];

        self::assertSame('rec_p', $payload['parent_id']);
        self::assertSame('thinking', $payload['branch']);
        self::assertSame('Achilles wins.', $payload['summary']);
    }

    #[Test]
    public function metadataPayloadShape(): void
    {
        $record = new Metadata('r', 0, self::at(), key: 'session_id', value: 'ses_xyz');
        $payload = $record->toCanonical()['payload'];

        self::assertSame('session_id', $payload['key']);
        self::assertSame('ses_xyz', $payload['value']);
    }

    #[Test]
    public function metadataIntValueSerializes(): void
    {
        $record = new Metadata('r', 0, self::at(), key: 'tokens', value: 1500);
        $payload = $record->toCanonical()['payload'];

        self::assertSame(1500, $payload['value']);
    }

    #[Test]
    public function errorPayloadShape(): void
    {
        $record = new Error(
            'r',
            0,
            self::at(),
            code: 'oracle.timeout',
            message: 'No response from the oracle.',
            details: ['retries' => 3],
        );
        $payload = $record->toCanonical()['payload'];

        self::assertSame('oracle.timeout', $payload['code']);
        self::assertSame('No response from the oracle.', $payload['message']);
        self::assertSame(['retries' => 3], $payload['details']);
    }

    #[Test]
    public function unknownPayloadShape(): void
    {
        $record = new Unknown(
            'r',
            0,
            self::at(),
            rawJson: '{"kind":"olympus.custom"}',
            parserHint: 'olympus.custom',
        );
        $payload = $record->toCanonical()['payload'];

        self::assertSame('{"kind":"olympus.custom"}', $payload['raw_json']);
        self::assertSame('olympus.custom', $payload['parser_hint']);
    }

    #[Test]
    public function nullSequenceSerializesAsNull(): void
    {
        $record = new Message('r', null, self::at(), role: 'user', text: 'agora');
        $canonical = $record->toCanonical();

        self::assertNull($canonical['sequence']);
    }

    #[Test]
    public function metadataPayloadCarriesFloatValue(): void
    {
        $record = new Metadata(
            id: 'meta_1',
            sequence: null,
            at: new \DateTimeImmutable('2026-05-17T12:00:00Z'),
            key: 'temperature',
            value: 0.7,
        );

        self::assertSame(0.7, $record->toCanonical()['payload']['value']);
    }

    #[Test]
    public function metadataPayloadCarriesBoolValue(): void
    {
        $record = new Metadata(
            id: 'meta_2',
            sequence: null,
            at: new \DateTimeImmutable('2026-05-17T12:00:00Z'),
            key: 'streaming',
            value: true,
        );

        self::assertTrue($record->toCanonical()['payload']['value']);
    }

    #[Test]
    public function errorPayloadDefaultsToEmptyDetails(): void
    {
        $record = new Error(
            id: 'err_1',
            sequence: null,
            at: new \DateTimeImmutable('2026-05-17T12:00:00Z'),
            code: 'parse-failed',
            message: 'unable to decode JSONL line',
        );

        self::assertSame([], $record->toCanonical()['payload']['details']);
    }

    #[Test]
    public function unknownPayloadDefaultsToNullParserHint(): void
    {
        $record = new Unknown(
            id: 'unk_1',
            sequence: null,
            at: new \DateTimeImmutable('2026-05-17T12:00:00Z'),
            rawJson: '{"weird":"shape"}',
        );

        self::assertNull($record->toCanonical()['payload']['parser_hint']);
    }

    private static function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-05-17T12:00:00Z');
    }
}
