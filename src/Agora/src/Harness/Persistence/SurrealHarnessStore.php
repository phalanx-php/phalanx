<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Phalanx\Agora\Harness\ProjectionCheckpoint;
use Phalanx\Agora\Harness\ProjectionKind;
use Phalanx\Agora\Harness\ProjectionSet;
use Phalanx\Agora\Harness\Replay\ProjectionCheckpointReader;
use Phalanx\Agora\Harness\ResumePoint;
use Phalanx\Agora\Harness\ResumeStatus;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealException;

final class SurrealHarnessStore implements ProjectionCheckpointReader
{
    private SurrealEventLog $events;

    public function __construct(
        private Surreal $surreal,
        ?SurrealEventLog $events = null,
    ) {
        $this->events = $events ?? new SurrealEventLog($surreal);
    }

    public function events(): SurrealEventLog
    {
        return $this->events;
    }

    public function createSession(
        string $sessionId,
        DateTimeImmutable $startedAt,
        ?string $title = null,
        ?string $model = null,
        ?string $provider = null,
        ?string $workingDirectory = null,
    ): void {
        $sessionRecord = self::recordLiteral('agora_session', $sessionId);

        $this->statementResults($this->surreal->queryRaw(
            <<<SURREAL
            CREATE ONLY {$sessionRecord} SET
                title = {$this->literal($title)},
                status = 'active',
                model = {$this->literal($model)},
                provider = {$this->literal($provider)},
                working_directory = {$this->literal($workingDirectory)},
                started_at = {$this->datetimeLiteral(self::formatInstant($startedAt))};
            SURREAL,
        ));
    }

    public function createTurn(
        string $sessionId,
        string $turnId,
        int $sequence,
        string $actor,
        DateTimeImmutable $startedAt,
        string $status = 'streaming',
        ?string $promptHash = null,
    ): void {
        $sessionRecord = self::recordLiteral('agora_session', $sessionId);
        $turnRecord = self::recordLiteral('agora_turn', $turnId);

        $this->statementResults($this->surreal->queryRaw(
            <<<SURREAL
            CREATE ONLY {$turnRecord} SET
                session_id = {$sessionRecord},
                sequence = {$sequence},
                actor = {$this->literal($actor)},
                status = {$this->literal($status)},
                prompt_hash = {$this->literal($promptHash)},
                started_at = {$this->datetimeLiteral(self::formatInstant($startedAt))};
            SURREAL,
        ));
    }

    public function saveCheckpoints(
        ProjectionSet $projections,
        ?DateTimeImmutable $createdAt = null,
    ): void {
        $statements = ['BEGIN;'];
        foreach ($projections->checkpoints($createdAt) as $checkpoint) {
            $statements[] = $this->checkpointCreateStatement($checkpoint);
        }
        $statements[] = 'COMMIT;';

        $this->statementResults($this->surreal->queryRaw(implode("\n", $statements)));
    }

    public function latestProjectionSet(
        string $sessionId,
    ): ?ProjectionSet {
        $sessionRecord = self::recordLiteral('agora_session', $sessionId);

        $result = $this->statementResults($this->surreal->queryRaw(
            <<<SURREAL
            SELECT *
            FROM agora_replay_checkpoint
            WHERE session_id = {$sessionRecord}
            ORDER BY event_sequence DESC, created_at DESC;
            SURREAL,
        ));

        $rows = $result[0] ?? [];
        if (!is_array($rows)) {
            throw new SurrealException('Surreal checkpoint read returned an invalid result.');
        }

        $groups = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new SurrealException('Surreal checkpoint read returned an invalid row.');
            }

            $sequence = $row['event_sequence'] ?? null;
            if (!is_int($sequence)) {
                throw new SurrealException('Surreal checkpoint row was missing event_sequence.');
            }

            $groups[$sequence][] = self::checkpointFromRow($row, $sessionId);
        }

        foreach ($groups as $checkpoints) {
            if (count($checkpoints) === count(ProjectionKind::cases())) {
                return ProjectionSet::fromCheckpoints($checkpoints);
            }
        }

        return null;
    }

    public function saveResumePoint(
        ResumePoint $resume,
    ): void {
        $sessionRecord = self::recordLiteral('agora_session', $resume->sessionId);
        $resumeRecord = self::recordLiteral('agora_resume_state', $resume->sessionId);
        $turnRecord = $resume->turnId === null ? 'NONE' : self::recordLiteral('agora_turn', $resume->turnId);
        $pendingEffectRecord = 'NONE';
        if ($resume->pendingEffectRecordId !== null) {
            $pendingEffectRecord = self::recordLiteral(
                'agora_effect',
                self::recordKey($resume->pendingEffectRecordId, 'agora_effect'),
            );
        }

        $this->statementResults($this->surreal->queryRaw(
            <<<SURREAL
            UPSERT ONLY {$resumeRecord} SET
                session_id = {$sessionRecord},
                turn_id = {$turnRecord},
                event_sequence = {$resume->eventSequence},
                status = {$this->literal($resume->status->value)},
                pending_effect_record_id = {$pendingEffectRecord},
                serialized_context = {$this->objectLiteral($resume->serializedContext)},
                updated_at = {$this->datetimeLiteral(self::formatInstant($resume->updatedAt))};
            SURREAL,
        ));
    }

    public function resumePoint(
        string $sessionId,
    ): ?ResumePoint {
        $resumeRecord = self::recordLiteral('agora_resume_state', $sessionId);

        $result = $this->statementResults($this->surreal->queryRaw(
            <<<SURREAL
            SELECT *
            FROM ONLY {$resumeRecord};
            SURREAL,
        ));

        $row = $result[0] ?? null;
        if ($row === null) {
            return null;
        }

        if (!is_array($row) || array_is_list($row)) {
            throw new SurrealException('Surreal resume-state read returned an invalid row.');
        }

        return new ResumePoint(
            sessionId: $sessionId,
            turnId: self::nullableRecordKey($row['turn_id'] ?? null, 'agora_turn'),
            eventSequence: self::intField($row, 'event_sequence'),
            status: ResumeStatus::from(self::stringField($row, 'status')),
            pendingEffectRecordId: self::nullableRecordId($row['pending_effect_record_id'] ?? null, 'agora_effect'),
            serializedContext: self::arrayField($row['serialized_context'] ?? []),
            updatedAt: self::instant($row['updated_at'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function checkpointFromRow(
        array $row,
        string $sessionId,
    ): ProjectionCheckpoint {
        return new ProjectionCheckpoint(
            sessionId: $sessionId,
            stateKind: ProjectionKind::from(self::stringField($row, 'state_kind')),
            eventSequence: self::intField($row, 'event_sequence'),
            schemaVersion: self::intField($row, 'schema_version'),
            projectionHash: self::stringField($row, 'projection_hash'),
            state: self::arrayField($row['state'] ?? []),
            createdAt: self::instant($row['created_at'] ?? null),
        );
    }

    private static function formatInstant(
        DateTimeImmutable $instant,
    ): string {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function instant(
        mixed $value,
    ): DateTimeImmutable {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_string($value)) {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }

        throw new SurrealException('Surreal row instant was missing or invalid.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function intField(
        array $row,
        string $field,
    ): int {
        $value = $row[$field] ?? null;
        if (!is_int($value)) {
            throw new SurrealException("Surreal row field {$field} was missing or invalid.");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayField(
        mixed $value,
    ): array {
        if ($value === []) {
            return [];
        }

        if (!is_array($value) || array_is_list($value)) {
            throw new SurrealException('Surreal row object field was missing or invalid.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function stringField(
        array $row,
        string $field,
    ): string {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new SurrealException("Surreal row field {$field} was missing or invalid.");
        }

        return $value;
    }

    private static function nullableRecordId(
        mixed $value,
        string $table,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || !str_starts_with($value, $table . ':')) {
            throw new SurrealException("Surreal {$table} record reference was missing or invalid.");
        }

        return $value;
    }

    private static function nullableRecordKey(
        ?string $recordId,
        string $table,
    ): ?string {
        if ($recordId === null) {
            return null;
        }

        $prefix = $table . ':';
        if (!str_starts_with($recordId, $prefix)) {
            throw new SurrealException("Surreal {$table} record reference was missing or invalid.");
        }

        $key = substr($recordId, strlen($prefix));
        if (str_starts_with($key, '`') && str_ends_with($key, '`')) {
            return substr($key, 1, -1);
        }

        return $key;
    }

    private static function recordKey(
        string $recordId,
        string $table,
    ): string {
        $key = self::nullableRecordKey($recordId, $table);
        if ($key === null) {
            throw new SurrealException("Surreal {$table} record reference was missing or invalid.");
        }

        return $key;
    }

    private static function recordLiteral(
        string $table,
        string $key,
    ): string {
        if (str_contains($key, '`')) {
            throw new \InvalidArgumentException('Surreal record keys cannot contain backticks.');
        }

        return sprintf('%s:`%s`', $table, $key);
    }

    private function checkpointCreateStatement(
        ProjectionCheckpoint $checkpoint,
    ): string {
        $sessionRecord = self::recordLiteral('agora_session', $checkpoint->sessionId);
        $checkpointRecord = self::recordLiteral(
            'agora_replay_checkpoint',
            sprintf('%s.%d.%s', $checkpoint->sessionId, $checkpoint->eventSequence, $checkpoint->stateKind->value),
        );

        return <<<SURREAL
        UPSERT ONLY {$checkpointRecord} SET
            session_id = {$sessionRecord},
            event_sequence = {$checkpoint->eventSequence},
            state_kind = {$this->literal($checkpoint->stateKind->value)},
            schema_version = {$checkpoint->schemaVersion},
            projection_hash = {$this->literal($checkpoint->projectionHash)},
            state = {$this->objectLiteral($checkpoint->state)},
            created_at = {$this->datetimeLiteral(self::formatInstant($checkpoint->createdAt))};
        SURREAL;
    }

    /**
     * @param list<mixed>|null $raw
     * @return list<mixed>
     */
    private function statementResults(
        ?array $raw,
    ): array {
        if ($raw === null) {
            throw new SurrealException('Surreal query returned no statement results.');
        }

        $results = [];
        foreach ($raw as $statement) {
            if (!is_array($statement)) {
                $results[] = $statement;

                continue;
            }

            $status = $statement['status'] ?? 'OK';
            if ($status === 'ERR') {
                $detail = $statement['result'] ?? 'unknown Surreal error';
                throw new SurrealException('Surreal query failed: ' . (is_string($detail) ? $detail : json_encode($detail)));
            }

            $results[] = array_key_exists('result', $statement) ? $statement['result'] : $statement;
        }

        return $results;
    }

    private function datetimeLiteral(
        string $value,
    ): string {
        return "d'" . str_replace("'", "\\'", $value) . "'";
    }

    private function literal(
        mixed $value,
    ): string {
        if ($value === null) {
            return 'NONE';
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new SurrealException("Failed to encode Surreal literal: {$e->getMessage()}", previous: $e);
        }
    }

    private function objectLiteral(
        mixed $value,
    ): string {
        if ($value === []) {
            return '{}';
        }

        return $this->literal($value);
    }
}
