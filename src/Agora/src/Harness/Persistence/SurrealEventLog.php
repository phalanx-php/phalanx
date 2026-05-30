<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Persistence;

use Phalanx\Agora\Harness\EventReader;
use Phalanx\Agora\Harness\HarnessEvent;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealException;

final class SurrealEventLog implements EventReader
{
    private HarnessEventMapper $mapper;

    public function __construct(
        private Surreal $surreal,
        ?HarnessEventMapper $mapper = null,
    ) {
        $this->mapper = $mapper ?? new HarnessEventMapper();
    }

    public function append(
        HarnessEventDraft $draft,
    ): HarnessEvent {
        $data = $draft->toRecordData();
        $existing = $this->eventBySourceKey($draft->sessionId, $data['sourcekey']);
        if ($existing !== null) {
            return $existing;
        }

        $sessionRecord = self::recordLiteral('agora_session', $draft->sessionId);
        $turnRecord = $draft->turnId === null ? 'NONE' : self::recordLiteral('agora_turn', $draft->turnId);
        $counterRecord = self::recordLiteral('agora_event_sequence', $draft->sessionId);

        try {
            $result = $this->statementResults(
                $this->surreal->queryRaw(
                    <<<SURREAL
                    BEGIN;
                    LET \$counter = (
                        UPSERT ONLY {$counterRecord}
                        SET
                            session_id = {$sessionRecord},
                            event_sequence += 1,
                            updated_at = time::now()
                    );
                    CREATE agora_event SET
                        session_id = {$sessionRecord},
                        turn_id = {$turnRecord},
                        sequence = \$counter.event_sequence,
                        cue_id = {$this->literal($data['cueid'])},
                        cue_type = {$this->literal($data['cuetype'])},
                        channel = {$this->literal($data['channel'])},
                        source = {$this->literal($data['source'])},
                        source_key = {$this->literal($data['sourcekey'])},
                        payload = {$this->objectLiteral($data['payload'])},
                        occurred_at = {$this->datetimeLiteral($data['occurred'])},
                        received_at = {$this->datetimeLiteral($data['received'])};
                    COMMIT;
                    SURREAL,
                ),
            );
        } catch (SurrealException $e) {
            $existing = $this->eventBySourceKey($draft->sessionId, $data['sourcekey']);
            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }

        $created = $result[2] ?? null;
        if (is_array($created) && array_is_list($created)) {
            $created = $created[0] ?? null;
        }

        if (!is_array($created) || array_is_list($created)) {
            throw new SurrealException('Surreal event append did not return a created event row.');
        }

        return $this->mapper->fromRow($created, $draft->sessionId);
    }

    /** @return iterable<HarnessEvent> */
    public function readAfter(
        string $sessionId,
        int $sequence,
    ): iterable {
        $sessionRecord = self::recordLiteral('agora_session', $sessionId);

        $result = $this->statementResults($this->surreal->queryRaw(
            <<<SURREAL
            SELECT *
            FROM agora_event
            WHERE session_id = {$sessionRecord}
              AND sequence > {$sequence}
            ORDER BY sequence ASC;
            SURREAL,
        ));

        $rows = $result[0] ?? [];
        if (!is_array($rows)) {
            throw new SurrealException('Surreal event read returned an invalid result.');
        }

        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new SurrealException('Surreal event read returned an invalid row.');
            }

            yield $this->mapper->fromRow($row, $sessionId);
        }
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
        mixed $value,
    ): string {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Surreal datetime literal requires a string value.');
        }

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

    private function eventBySourceKey(
        string $sessionId,
        mixed $sourceKey,
    ): ?HarnessEvent {
        if (!is_string($sourceKey)) {
            throw new \InvalidArgumentException('Harness event source key must be a string.');
        }

        $sessionRecord = self::recordLiteral('agora_session', $sessionId);
        $result = $this->statementResults($this->surreal->queryRaw(
            <<<SURREAL
            SELECT *
            FROM agora_event
            WHERE session_id = {$sessionRecord}
              AND source_key = {$this->literal($sourceKey)}
            LIMIT 1;
            SURREAL,
        ));

        $rows = $result[0] ?? [];
        if (!is_array($rows)) {
            throw new SurrealException('Surreal event source-key read returned an invalid result.');
        }

        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }

        if (!is_array($row) || array_is_list($row)) {
            throw new SurrealException('Surreal event source-key read returned an invalid row.');
        }

        return $this->mapper->fromRow($row, $sessionId);
    }
}
