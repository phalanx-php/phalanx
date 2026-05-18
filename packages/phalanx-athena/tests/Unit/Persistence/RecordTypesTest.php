<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Persistence;

use Phalanx\Athena\Activity\State;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Persistence\ActivityRecord;
use Phalanx\Athena\Persistence\EffectLogRecord;
use Phalanx\Athena\Persistence\InvocationRecord;
use Phalanx\Athena\Persistence\PromptHashRecord;
use Phalanx\Athena\Persistence\SuspendedState;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Effect\Kind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordTypesTest extends TestCase
{
    /** @return array<string, array{State}> */
    public static function allActivityStates(): array
    {
        return [
            'pending'   => [State::Pending],
            'running'   => [State::Running],
            'suspended' => [State::Suspended],
            'completed' => [State::Completed],
            'failed'    => [State::Failed],
            'cancelled' => [State::Cancelled],
        ];
    }

    /** @return array<string, array{Resolution}> */
    public static function allResolutions(): array
    {
        return [
            'built-in'   => [Resolution::BuiltIn],
            'local-tool' => [Resolution::LocalTool],
            'mcp-tool'   => [Resolution::McpTool],
            'sub-agent'  => [Resolution::SubAgent],
        ];
    }

    #[Test]
    public function activityRecordCarriesAllProperties(): void
    {
        $startedAt = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $completedAt = new \DateTimeImmutable('2026-01-01T00:01:00Z');

        $record = new ActivityRecord(
            id: 'act_01',
            agentId: 'agent_01',
            state: State::Completed,
            startedAt: $startedAt,
            completedAt: $completedAt,
            invocationCount: 3,
        );

        self::assertSame('act_01', $record->id);
        self::assertSame('agent_01', $record->agentId);
        self::assertSame(State::Completed, $record->state);
        self::assertSame($startedAt, $record->startedAt);
        self::assertSame($completedAt, $record->completedAt);
        self::assertSame(3, $record->invocationCount);
    }

    #[Test]
    public function activityRecordDefaultsCompletedAtAndInvocationCount(): void
    {
        $record = new ActivityRecord(
            id: 'act_02',
            agentId: 'agent_01',
            state: State::Pending,
            startedAt: new \DateTimeImmutable(),
        );

        self::assertNull($record->completedAt);
        self::assertSame(0, $record->invocationCount);
    }

    #[Test]
    #[DataProvider('allActivityStates')]
    public function activityRecordAcceptsAllStates(State $state): void
    {
        $record = new ActivityRecord(
            id: 'act_03',
            agentId: 'agent_01',
            state: $state,
            startedAt: new \DateTimeImmutable(),
        );

        self::assertSame($state, $record->state);
    }

    #[Test]
    public function invocationRecordCarriesAllProperties(): void
    {
        $at = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $completedAt = new \DateTimeImmutable('2026-01-01T00:00:05Z');

        $record = new InvocationRecord(
            id: 'inv_01',
            activityId: 'act_01',
            promptHash: 'abc123',
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            at: $at,
            completedAt: $completedAt,
        );

        self::assertSame('inv_01', $record->id);
        self::assertSame('act_01', $record->activityId);
        self::assertSame('abc123', $record->promptHash);
        self::assertSame('anthropic', $record->provider);
        self::assertSame('claude-sonnet-4-6', $record->model);
        self::assertSame($at, $record->at);
        self::assertSame($completedAt, $record->completedAt);
    }

    #[Test]
    public function invocationRecordDefaultsCompletedAt(): void
    {
        $record = new InvocationRecord(
            id: 'inv_02',
            activityId: 'act_01',
            promptHash: 'abc123',
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            at: new \DateTimeImmutable(),
        );

        self::assertNull($record->completedAt);
    }

    #[Test]
    #[DataProvider('allResolutions')]
    public function effectLogRecordAcceptsAllResolutions(Resolution $resolution): void
    {
        $record = new EffectLogRecord(
            id: 'eff_01',
            invocationId: 'inv_01',
            kind: 'tool_call',
            toolName: 'read_file',
            argsHash: 'def456',
            resolution: $resolution,
            outcome: 'ok',
            at: new \DateTimeImmutable(),
        );

        self::assertSame($resolution, $record->resolution);
    }

    #[Test]
    public function effectLogRecordCarriesAllProperties(): void
    {
        $at = new \DateTimeImmutable('2026-01-01T00:00:10Z');

        $record = new EffectLogRecord(
            id: 'eff_02',
            invocationId: 'inv_01',
            kind: 'tool_call',
            toolName: 'bash',
            argsHash: 'ghi789',
            resolution: Resolution::LocalTool,
            outcome: 'exit_0',
            at: $at,
        );

        self::assertSame('eff_02', $record->id);
        self::assertSame('inv_01', $record->invocationId);
        self::assertSame('tool_call', $record->kind);
        self::assertSame('bash', $record->toolName);
        self::assertSame('ghi789', $record->argsHash);
        self::assertSame(Resolution::LocalTool, $record->resolution);
        self::assertSame('exit_0', $record->outcome);
        self::assertSame($at, $record->at);
    }

    #[Test]
    public function promptHashRecordCarriesAllProperties(): void
    {
        $at = new \DateTimeImmutable('2026-01-01T00:00:00Z');

        $record = new PromptHashRecord(
            hash: 'sha256_abc',
            activityId: 'act_01',
            invocationId: 'inv_01',
            at: $at,
        );

        self::assertSame('sha256_abc', $record->hash);
        self::assertSame('act_01', $record->activityId);
        self::assertSame('inv_01', $record->invocationId);
        self::assertSame($at, $record->at);
    }

    #[Test]
    public function activityRecordDefaultsSuspensionFieldsToNull(): void
    {
        $record = new ActivityRecord(
            id: 'act_10',
            agentId: 'agent_01',
            state: State::Pending,
            startedAt: new \DateTimeImmutable(),
        );

        self::assertNull($record->serializedLog);
        self::assertNull($record->pendingEffectId);
        self::assertNull($record->pendingEffectPayload);
    }

    #[Test]
    public function activityRecordCarriesSuspensionFields(): void
    {
        $record = new ActivityRecord(
            id: 'act_11',
            agentId: 'agent_01',
            state: State::Suspended,
            startedAt: new \DateTimeImmutable(),
            serializedLog: '[]',
            pendingEffectId: 'write_file',
            pendingEffectPayload: '{"effect_id":"write_file"}',
        );

        self::assertSame('[]', $record->serializedLog);
        self::assertSame('write_file', $record->pendingEffectId);
        self::assertSame('{"effect_id":"write_file"}', $record->pendingEffectPayload);
    }

    #[Test]
    public function suspendedStateCarriesRecordLogAndPendingEffect(): void
    {
        $record = new ActivityRecord(
            id: 'act_12',
            agentId: 'agent_01',
            state: State::Suspended,
            startedAt: new \DateTimeImmutable(),
        );

        $log = Log::from([]);

        $effect = new Requested(
            id: 'cue_1',
            sequence: 1,
            activityId: 'act_12',
            invocationId: null,
            agentId: 'agent_01',
            at: new \DateTimeImmutable(),
            effectId: 'write_file',
            kind: Kind::FileWrite,
            summary: 'write a file',
            requiresApproval: true,
        );

        $state = new SuspendedState($record, $log, $effect);

        self::assertSame($record, $state->record);
        self::assertSame($log, $state->log);
        self::assertSame($effect, $state->pendingEffect);
    }
}
