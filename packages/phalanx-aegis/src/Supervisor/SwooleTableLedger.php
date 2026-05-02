<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

use Closure;
use OpenSwoole\Table;
use Phalanx\Cancellation\CancellationToken;
use RuntimeException;
use Throwable;

/**
 * Shared-memory LedgerStorage backend for OpenSwoole process topologies.
 *
 * Swoole\Table can only store scalar columns, so nested fields are encoded as
 * bounded JSON strings. The backend is opt-in; InProcessLedger remains the
 * default for single-process applications.
 */
final class SwooleTableLedger implements LedgerStorage
{
    /** @var array<string, CancellationToken> */
    private array $tokens = [];

    private readonly Table $table;

    public function __construct(int $size = 1024, ?Table $table = null)
    {
        $this->table = $table ?? self::createTable($size);
    }

    private static function createTable(int $size): Table
    {
        $table = new Table($size);
        $table->column('name', Table::TYPE_STRING, 512);
        $table->column('parent_id', Table::TYPE_STRING, 64);
        $table->column('mode', Table::TYPE_STRING, 16);
        $table->column('state', Table::TYPE_STRING, 16);
        $table->column('current_wait', Table::TYPE_STRING, 1024);
        $table->column('child_ids', Table::TYPE_STRING, 4096);
        $table->column('leases', Table::TYPE_STRING, 8192);
        $table->column('started_at', Table::TYPE_FLOAT);
        $table->column('ended_at', Table::TYPE_FLOAT);
        $table->column('has_ended', Table::TYPE_INT, 1);
        $table->create();

        return $table;
    }

    /** @return list<string> */
    private static function decodeList(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private static function decodeWait(string $json): ?WaitReason
    {
        if ($json === '') {
            return null;
        }

        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            return null;
        }

        return new WaitReason(
            WaitKind::from((string) $data['kind']),
            (string) $data['detail'],
            (float) $data['startedAt'],
        );
    }

    /**
     * @param list<Lease> $leases
     * @return list<array{type: string, domain: string, key: string, mode: string, acquiredAt: float}>
     */
    private static function normalizeLeases(array $leases): array
    {
        $out = [];
        foreach ($leases as $lease) {
            $out[] = [
                'type' => $lease::class,
                'domain' => $lease->domain,
                'key' => $lease->key,
                'mode' => $lease->mode,
                'acquiredAt' => $lease->acquiredAt,
            ];
        }

        return $out;
    }

    /** @return list<Lease> */
    private static function decodeLeases(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }

        $leases = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $leases[] = match ((string) $row['type']) {
                PoolLease::class => new PoolLease(
                    (string) $row['domain'],
                    (string) $row['key'],
                    (float) $row['acquiredAt'],
                ),
                TransactionLease::class => new TransactionLease(
                    (string) $row['domain'],
                    (string) $row['key'],
                    (float) $row['acquiredAt'],
                ),
                LockLease::class => new LockLease(
                    (string) $row['domain'],
                    (string) $row['key'],
                    (string) $row['mode'],
                    (float) $row['acquiredAt'],
                ),
                default => throw new RuntimeException("unknown lease type '{$row['type']}'"),
            };
        }

        return $leases;
    }

    private static function encodeWait(?WaitReason $reason): string
    {
        if ($reason === null) {
            return '';
        }

        return self::encode([
            'kind' => $reason->kind->value,
            'detail' => $reason->detail,
            'startedAt' => $reason->startedAt,
        ]);
    }

    /** @param list<Lease> $leases */
    private static function encodeLeases(array $leases): string
    {
        return self::encode(self::normalizeLeases($leases));
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private static function project(TaskRun $run): TaskRunSnapshot
    {
        $leases = [];
        foreach ($run->leases as $lease) {
            $leases[] = [
                'domain' => $lease->domain,
                'key' => $lease->key,
                'mode' => $lease->mode,
                'acquiredAt' => $lease->acquiredAt,
            ];
        }

        return new TaskRunSnapshot(
            id: $run->id,
            name: $run->name,
            parentId: $run->parentId,
            mode: $run->mode,
            state: $run->state,
            currentWait: $run->currentWait,
            childIds: $run->childIds,
            leases: $leases,
            startedAt: $run->startedAt,
            endedAt: $run->endedAt,
        );
    }

    public function register(TaskRun $run): void
    {
        $this->tokens[$run->id] = $run->cancellation;
        $this->persist($run);
    }

    public function update(string $runId, Closure $patch): void
    {
        $run = $this->find($runId);
        if ($run === null) {
            return;
        }

        if ($patch($run) === false) {
            return;
        }

        $this->persist($run);
    }

    public function complete(string $runId, mixed $value): void
    {
        $this->update($runId, static function (TaskRun $run) use ($value): void {
            $run->state = RunState::Completed;
            $run->value = $value;
            $run->endedAt = microtime(true);
            $run->currentWait = null;
        });
    }

    public function fail(string $runId, Throwable $error): void
    {
        $this->update($runId, static function (TaskRun $run) use ($error): void {
            $run->state = RunState::Failed;
            $run->error = $error;
            $run->endedAt = microtime(true);
            $run->currentWait = null;
        });
    }

    public function cancel(string $runId): void
    {
        $this->update($runId, static function (TaskRun $run): void {
            $run->state = RunState::Cancelled;
            $run->endedAt = microtime(true);
            $run->currentWait = null;
        });
    }

    public function find(string $runId): ?TaskRun
    {
        $row = $this->table->get($runId);
        if (!is_array($row)) {
            return null;
        }

        return $this->hydrate($runId, $row);
    }

    public function snapshot(string $runId): ?TaskRunSnapshot
    {
        $run = $this->find($runId);
        return $run === null ? null : self::project($run);
    }

    public function tree(?string $rootRunId = null): array
    {
        if ($rootRunId === null) {
            $out = [];
            foreach ($this->table as $id => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $out[] = self::project($this->hydrate((string) $id, $row));
            }

            return $out;
        }

        $root = $this->find($rootRunId);
        if ($root === null) {
            return [];
        }

        $out = [self::project($root)];
        foreach ($root->childIds as $childId) {
            foreach ($this->tree($childId) as $descendant) {
                $out[] = $descendant;
            }
        }

        return $out;
    }

    public function liveCount(): int
    {
        $live = 0;
        foreach ($this->table as $id => $row) {
            if (!is_array($row)) {
                continue;
            }

            $run = $this->hydrate((string) $id, $row);
            if (!$run->isTerminal()) {
                $live++;
            }
        }

        return $live;
    }

    public function reap(string $runId): void
    {
        unset($this->tokens[$runId]);
        $this->table->del($runId);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(string $id, array $row): TaskRun
    {
        $run = new TaskRun(
            id: $id,
            name: (string) $row['name'],
            parentId: $row['parent_id'] === '' ? null : (string) $row['parent_id'],
            mode: DispatchMode::from((string) $row['mode']),
            cancellation: $this->tokens[$id] ?? CancellationToken::none(),
            startedAt: (float) $row['started_at'],
        );
        $run->state = RunState::from((string) $row['state']);
        $run->currentWait = self::decodeWait((string) $row['current_wait']);
        $run->childIds = self::decodeList((string) $row['child_ids']);
        $run->leases = self::decodeLeases((string) $row['leases']);
        $run->endedAt = (int) $row['has_ended'] === 1 ? (float) $row['ended_at'] : null;

        return $run;
    }

    private function persist(TaskRun $run): void
    {
        $ok = $this->table->set($run->id, [
            'name' => $run->name,
            'parent_id' => $run->parentId ?? '',
            'mode' => $run->mode->value,
            'state' => $run->state->value,
            'current_wait' => self::encodeWait($run->currentWait),
            'child_ids' => self::encode($run->childIds),
            'leases' => self::encodeLeases($run->leases),
            'started_at' => $run->startedAt,
            'ended_at' => $run->endedAt ?? 0.0,
            'has_ended' => $run->endedAt === null ? 0 : 1,
        ]);

        if (!$ok) {
            throw new RuntimeException("failed to persist TaskRun '{$run->id}' to Swoole table");
        }
    }
}
