<?php

declare(strict_types=1);

namespace Phalanx\Surreal\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgoraHarnessSchemaTest extends TestCase
{
    #[Test]
    public function schemaDefinesHarnessPersistenceSurfaces(): void
    {
        $schema = self::schema();

        foreach (self::requiredTables() as $table) {
            self::assertStringContainsString("DEFINE TABLE {$table} TYPE NORMAL SCHEMAFULL", $schema);
        }

        self::assertStringNotContainsString('DEFINE FIELD id ON TABLE', $schema);
    }

    #[Test]
    public function schemaDefinesCueEventLogSurface(): void
    {
        $schema = self::schema();

        self::assertStringContainsString(
            'DEFINE TABLE agora_event TYPE NORMAL SCHEMAFULL' . PHP_EOL
            . '    PERMISSIONS FOR select, create WHERE true, FOR update, delete NONE;',
            $schema,
        );
        self::assertStringContainsString(
            'DEFINE INDEX agora_event_session_sequence ON TABLE agora_event FIELDS session_id, sequence UNIQUE;',
            $schema,
        );
        self::assertStringContainsString('DEFINE FIELD cue_type ON TABLE agora_event TYPE string;', $schema);
        self::assertStringContainsString(
            "DEFINE FIELD source ON TABLE agora_event TYPE string ASSERT \$value INSIDE ['panoply', 'athena', 'theatron', 'agora', 'runtime'];",
            $schema,
        );
        self::assertStringContainsString(
            'DEFINE FIELD payload ON TABLE agora_event TYPE object;',
            $schema,
        );
    }

    #[Test]
    public function schemaSeparatesToolMcpEffectApprovalAndArtifactRecords(): void
    {
        $schema = self::schema();

        self::assertStringContainsString('DEFINE FIELD tool_name ON TABLE agora_tool_call TYPE string;', $schema);
        self::assertStringContainsString('DEFINE FIELD server_name ON TABLE agora_mcp_call TYPE string;', $schema);
        self::assertStringContainsString('DEFINE FIELD effect_id ON TABLE agora_effect TYPE string;', $schema);
        self::assertStringContainsString(
            'DEFINE FIELD effect_record_id ON TABLE agora_approval TYPE record<agora_effect>;',
            $schema,
        );
        self::assertStringContainsString('DEFINE FIELD effect_id ON TABLE agora_approval TYPE string;', $schema);
        self::assertStringContainsString('DEFINE FIELD decision ON TABLE agora_approval TYPE string', $schema);
        self::assertStringContainsString('DEFINE FIELD content_hash ON TABLE agora_artifact TYPE string;', $schema);
    }

    #[Test]
    public function schemaDefinesUsageReplayAndResumeSurfaces(): void
    {
        $schema = self::schema();

        self::assertStringContainsString('DEFINE FIELD total_tokens ON TABLE agora_usage TYPE option<int>;', $schema);
        self::assertStringContainsString(
            'DEFINE FIELD state_kind ON TABLE agora_replay_checkpoint TYPE string',
            $schema,
        );
        self::assertStringContainsString(
            'DEFINE FIELD state ON TABLE agora_replay_checkpoint TYPE object;',
            $schema,
        );
        self::assertStringContainsString(
            'DEFINE FIELD serialized_context ON TABLE agora_resume_state TYPE object;',
            $schema,
        );
        self::assertStringContainsString(
            "DEFINE FIELD status ON TABLE agora_resume_state TYPE string ASSERT \$value INSIDE ['ready', 'waiting_approval', 'streaming', 'failed', 'cancelled'];",
            $schema,
        );
    }

    #[Test]
    public function schemaDoesNotPersistTheatronImplementationNames(): void
    {
        $schema = self::schema();

        self::assertStringNotContainsString('DEFINE TABLE theatron_', $schema);
        self::assertStringNotContainsString('ConversationSlice', $schema);
        self::assertStringNotContainsString('DevToolsSlice', $schema);
        self::assertStringNotContainsString('ChatScreen', $schema);
    }

    private static function schema(): string
    {
        $schema = file_get_contents(dirname(__DIR__, 2) . '/resources/agora-harness-schema.surql');

        self::assertIsString($schema);

        return $schema;
    }

    /** @return list<string> */
    private static function requiredTables(): array
    {
        return [
            'agora_session',
            'agora_turn',
            'agora_event',
            'agora_tool_call',
            'agora_mcp_call',
            'agora_effect',
            'agora_approval',
            'agora_artifact',
            'agora_usage',
            'agora_replay_checkpoint',
            'agora_resume_state',
        ];
    }
}
