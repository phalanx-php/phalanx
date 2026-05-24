<?php

declare(strict_types=1);

namespace Phalanx\Agora\Tests\Unit;

use Phalanx\Agora\Agora;
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

        $openPermissions = 'PERMISSIONS FOR select, create WHERE true, FOR update, delete NONE;';
        self::assertMatchesRegularExpression(
            '/DEFINE TABLE agora_event TYPE NORMAL SCHEMAFULL\s+' . preg_quote($openPermissions, '/') . '/',
            $schema,
        );
        self::assertStringContainsString(
            'DEFINE INDEX agora_event_session_sequence ON TABLE agora_event FIELDS session_id, sequence UNIQUE;',
            $schema,
        );
        self::assertStringContainsString(
            'DEFINE TABLE agora_event_sequence TYPE NORMAL SCHEMAFULL',
            $schema,
        );
        self::assertStringContainsString(
            'DEFINE FIELD event_sequence ON TABLE agora_event_sequence TYPE int ASSERT $value >= 0;',
            $schema,
        );
        self::assertStringContainsString('DEFINE FIELD cue_type ON TABLE agora_event TYPE string;', $schema);
        self::assertStringContainsString(
            self::eventSourceField(),
            $schema,
        );
        self::assertStringNotContainsString("'theatron'", $schema);
        self::assertStringContainsString(
            'DEFINE FIELD payload ON TABLE agora_event TYPE object FLEXIBLE;',
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
        self::assertStringContainsString(
            self::pendingEffectRecordField(),
            $schema,
        );
        self::assertStringNotContainsString('DEFINE FIELD pending_effect_id ON TABLE', $schema);
        self::assertStringContainsString(
            'DEFINE FIELD arguments ON TABLE agora_tool_call TYPE option<object> FLEXIBLE;',
            $schema,
        );
        self::assertStringContainsString(
            'DEFINE FIELD arguments ON TABLE agora_mcp_call TYPE option<object> FLEXIBLE;',
            $schema,
        );
        self::assertStringContainsString(
            'DEFINE FIELD arguments ON TABLE agora_effect TYPE option<object> FLEXIBLE;',
            $schema,
        );
        self::assertStringContainsString('DEFINE FIELD content_hash ON TABLE agora_artifact TYPE string;', $schema);
        self::assertStringContainsString('DEFINE FIELD payload ON TABLE agora_artifact TYPE object FLEXIBLE;', $schema);
    }

    #[Test]
    public function schemaDefinesUsageReplayAndResumeSurfaces(): void
    {
        $schema = self::schema();

        self::assertStringContainsString('DEFINE FIELD input_tokens ON TABLE agora_usage TYPE option<int>;', $schema);
        self::assertStringContainsString('DEFINE FIELD output_tokens ON TABLE agora_usage TYPE option<int>;', $schema);
        self::assertStringContainsString('DEFINE FIELD cache_read_tokens ON TABLE agora_usage TYPE option<int>;', $schema);
        self::assertStringContainsString(
            'DEFINE FIELD cache_write_tokens ON TABLE agora_usage TYPE option<int>;',
            $schema,
        );
        self::assertStringContainsString('DEFINE FIELD total_tokens ON TABLE agora_usage TYPE option<int>;', $schema);
        self::assertStringContainsString('DEFINE FIELD cost_usd ON TABLE agora_usage TYPE option<float>;', $schema);
        self::assertStringNotContainsString('thinking_tokens', $schema);
        self::assertStringContainsString(
            'DEFINE FIELD state_kind ON TABLE agora_replay_checkpoint TYPE string',
            $schema,
        );
        self::assertStringContainsString(
            'DEFINE FIELD event_sequence ON TABLE agora_replay_checkpoint TYPE int ASSERT $value >= 0;',
            $schema,
        );
        self::assertStringContainsString(
            'DEFINE FIELD schema_version ON TABLE agora_replay_checkpoint TYPE int ASSERT $value >= 1;',
            $schema,
        );
        self::assertStringNotContainsString('DEFINE FIELD sequence ON TABLE agora_replay_checkpoint', $schema);
        self::assertStringContainsString(
            'DEFINE FIELD state ON TABLE agora_replay_checkpoint TYPE object FLEXIBLE;',
            $schema,
        );
        self::assertStringContainsString(
            'DEFINE FIELD serialized_context ON TABLE agora_resume_state TYPE object FLEXIBLE;',
            $schema,
        );
        self::assertStringContainsString(
            self::resumeStatusField(),
            $schema,
        );
        self::assertMatchesRegularExpression(
            '/DEFINE TABLE agora_resume_state TYPE NORMAL SCHEMAFULL\s+'
                . preg_quote('PERMISSIONS FOR select, create, update WHERE true, FOR delete NONE;', '/')
                . '/',
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

    #[Test]
    public function schemaKeepsOpenEndedObjectsFlexible(): void
    {
        $schema = self::schema();

        foreach (self::flexibleObjectFields() as $field) {
            self::assertStringContainsString($field, $schema);
        }
    }

    private static function schema(): string
    {
        $schema = file_get_contents(Agora::HARNESS_SCHEMA_RESOURCE);

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

    /** @return list<string> */
    private static function flexibleObjectFields(): array
    {
        return [
            'DEFINE FIELD metadata ON TABLE agora_session TYPE option<object> FLEXIBLE;',
            'DEFINE FIELD metadata ON TABLE agora_turn TYPE option<object> FLEXIBLE;',
            'DEFINE FIELD payload ON TABLE agora_event TYPE object FLEXIBLE;',
            'DEFINE FIELD arguments ON TABLE agora_tool_call TYPE option<object> FLEXIBLE;',
            'DEFINE FIELD result ON TABLE agora_tool_call TYPE option<object> FLEXIBLE;',
            'DEFINE FIELD arguments ON TABLE agora_mcp_call TYPE option<object> FLEXIBLE;',
            'DEFINE FIELD result ON TABLE agora_mcp_call TYPE option<object> FLEXIBLE;',
            'DEFINE FIELD arguments ON TABLE agora_effect TYPE option<object> FLEXIBLE;',
            'DEFINE FIELD outcome ON TABLE agora_effect TYPE option<object> FLEXIBLE;',
            'DEFINE FIELD payload ON TABLE agora_artifact TYPE object FLEXIBLE;',
            'DEFINE FIELD state ON TABLE agora_replay_checkpoint TYPE object FLEXIBLE;',
            'DEFINE FIELD serialized_context ON TABLE agora_resume_state TYPE object FLEXIBLE;',
        ];
    }

    private static function pendingEffectRecordField(): string
    {
        return 'DEFINE FIELD pending_effect_record_id ON TABLE agora_resume_state '
            . 'TYPE option<record<agora_effect>>;';
    }

    private static function eventSourceField(): string
    {
        return "DEFINE FIELD source ON TABLE agora_event TYPE string ASSERT \$value INSIDE "
            . "['panoply', 'athena', 'agora', 'runtime'];";
    }

    private static function resumeStatusField(): string
    {
        return "DEFINE FIELD status ON TABLE agora_resume_state TYPE string ASSERT \$value INSIDE "
            . "['ready', 'waiting_approval', 'streaming', 'failed', 'cancelled'];";
    }
}
