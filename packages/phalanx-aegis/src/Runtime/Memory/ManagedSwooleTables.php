<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

use OpenSwoole\Table;
use RuntimeException;

final class ManagedSwooleTables
{
    public readonly Table $resources;

    public readonly Table $resourceEdges;

    public readonly Table $resourceLeases;

    public readonly Table $resourceAnnotations;

    public readonly Table $resourceEvents;

    public readonly Table $counters;

    public readonly Table $claims;

    public readonly Table $symbols;

    private bool $destroyed = false;

    /** @var array<string, int> */
    private array $sizes;

    /** @var array<string, int> */
    private array $highWater = [];

    public function __construct(public readonly RuntimeMemoryConfig $config)
    {
        $this->sizes = [
            'resources' => $config->resourceRows,
            'resource_edges' => $config->edgeRows,
            'resource_leases' => $config->leaseRows,
            'resource_annotations' => $config->annotationRows,
            'resource_events' => $config->eventRows,
            'counters' => $config->counterRows,
            'claims' => $config->claimRows,
            'symbols' => $config->symbolRows,
        ];

        $this->resources = self::createResourceTable($config->resourceRows);
        $this->resourceEdges = self::createResourceEdgeTable($config->edgeRows);
        $this->resourceLeases = self::createResourceLeaseTable($config->leaseRows);
        $this->resourceAnnotations = self::createResourceAnnotationTable($config->annotationRows);
        $this->resourceEvents = self::createResourceEventTable($config->eventRows);
        $this->counters = self::createCounterTable($config->counterRows);
        $this->claims = self::createClaimTable($config->claimRows);
        $this->symbols = self::createSymbolTable($config->symbolRows);
    }

    private static function createResourceTable(int $rows): Table
    {
        return self::create($rows, static function (Table $table): void {
            $table->column('type_symbol', Table::TYPE_INT, 8);
            $table->column('parent_resource_id', Table::TYPE_STRING, 32);
            $table->column('owner_scope_id', Table::TYPE_STRING, 32);
            $table->column('owner_run_id', Table::TYPE_STRING, 32);
            $table->column('state', Table::TYPE_STRING, 16);
            $table->column('generation', Table::TYPE_INT, 8);
            $table->column('worker_id', Table::TYPE_INT, 8);
            $table->column('coroutine_id', Table::TYPE_INT, 8);
            $table->column('created_at', Table::TYPE_FLOAT);
            $table->column('updated_at', Table::TYPE_FLOAT);
            $table->column('terminal_at', Table::TYPE_FLOAT);
            $table->column('expires_at', Table::TYPE_FLOAT);
            $table->column('outcome', Table::TYPE_STRING, 32);
            $table->column('reason_symbol', Table::TYPE_INT, 8);
            $table->column('cancel_requested', Table::TYPE_INT, 1);
        });
    }

    private static function createResourceEdgeTable(int $rows): Table
    {
        return self::create($rows, static function (Table $table): void {
            $table->column('parent_resource_id', Table::TYPE_STRING, 32);
            $table->column('child_resource_id', Table::TYPE_STRING, 32);
            $table->column('edge_type', Table::TYPE_STRING, 32);
            $table->column('created_at', Table::TYPE_FLOAT);
            $table->column('expires_at', Table::TYPE_FLOAT);
        });
    }

    private static function createResourceLeaseTable(int $rows): Table
    {
        return self::create($rows, static function (Table $table): void {
            $table->column('owner_resource_id', Table::TYPE_STRING, 32);
            $table->column('owner_run_id', Table::TYPE_STRING, 32);
            $table->column('lease_type', Table::TYPE_STRING, 64);
            $table->column('domain', Table::TYPE_STRING, 128);
            $table->column('resource_key', Table::TYPE_STRING, 128);
            $table->column('mode', Table::TYPE_STRING, 16);
            $table->column('acquired_at', Table::TYPE_FLOAT);
            $table->column('expires_at', Table::TYPE_FLOAT);
        });
    }

    private static function createResourceAnnotationTable(int $rows): Table
    {
        return self::create($rows, static function (Table $table): void {
            $table->column('resource_id', Table::TYPE_STRING, 32);
            $table->column('key_symbol', Table::TYPE_INT, 8);
            $table->column('value', Table::TYPE_STRING, 256);
            $table->column('updated_at', Table::TYPE_FLOAT);
            $table->column('expires_at', Table::TYPE_FLOAT);
        });
    }

    private static function createResourceEventTable(int $rows): Table
    {
        return self::create($rows, static function (Table $table): void {
            $table->column('sequence', Table::TYPE_INT, 8);
            $table->column('event_type', Table::TYPE_STRING, 64);
            $table->column('resource_id', Table::TYPE_STRING, 32);
            $table->column('resource_type_symbol', Table::TYPE_INT, 8);
            $table->column('scope_id', Table::TYPE_STRING, 32);
            $table->column('run_id', Table::TYPE_STRING, 32);
            $table->column('state', Table::TYPE_STRING, 32);
            $table->column('occurred_at', Table::TYPE_FLOAT);
            $table->column('value_a', Table::TYPE_STRING, 128);
            $table->column('value_b', Table::TYPE_STRING, 128);
            $table->column('expires_at', Table::TYPE_FLOAT);
        });
    }

    private static function createCounterTable(int $rows): Table
    {
        return self::create($rows, static function (Table $table): void {
            $table->column('value', Table::TYPE_INT, 8);
            $table->column('updated_at', Table::TYPE_FLOAT);
        });
    }

    private static function createClaimTable(int $rows): Table
    {
        return self::create($rows, static function (Table $table): void {
            $table->column('token', Table::TYPE_STRING, 64);
            $table->column('claimed_at', Table::TYPE_FLOAT);
            $table->column('expires_at', Table::TYPE_FLOAT);
        });
    }

    private static function createSymbolTable(int $rows): Table
    {
        return self::create($rows, static function (Table $table): void {
            $table->column('id', Table::TYPE_INT, 8);
            $table->column('kind', Table::TYPE_STRING, 32);
            $table->column('value', Table::TYPE_STRING, 512);
            $table->column('created_at', Table::TYPE_FLOAT);
        });
    }

    /** @param \Closure(Table): void $columns */
    private static function create(int $rows, \Closure $columns): Table
    {
        $table = new Table($rows);
        $columns($table);

        if (!$table->create()) {
            throw new RuntimeException('failed to create OpenSwoole runtime table');
        }

        return $table;
    }

    public function mark(string $name): void
    {
        $table = $this->table($name);
        $this->highWater[$name] = max($this->highWater[$name] ?? 0, $table->count());
    }

    /** @return list<RuntimeTableStats> */
    public function stats(): array
    {
        $stats = [];
        foreach (array_keys($this->sizes) as $name) {
            $table = $this->table($name);
            $current = $table->count();
            $this->highWater[$name] = max($this->highWater[$name] ?? 0, $current);
            $stats[] = new RuntimeTableStats(
                name: $name,
                configuredRows: $this->sizes[$name],
                currentRows: $current,
                memorySize: $table->getMemorySize(),
                highWaterRows: $this->highWater[$name],
            );
        }

        return $stats;
    }

    public function destroy(): void
    {
        if ($this->destroyed) {
            return;
        }
        $this->destroyed = true;

        foreach (
            [
            $this->claims,
            $this->counters,
            $this->resourceEvents,
            $this->resourceAnnotations,
            $this->resourceLeases,
            $this->resourceEdges,
            $this->resources,
            $this->symbols,
            ] as $table
        ) {
            $table->destroy();
        }
    }

    private function table(string $name): Table
    {
        return match ($name) {
            'resources' => $this->resources,
            'resource_edges' => $this->resourceEdges,
            'resource_leases' => $this->resourceLeases,
            'resource_annotations' => $this->resourceAnnotations,
            'resource_events' => $this->resourceEvents,
            'counters' => $this->counters,
            'claims' => $this->claims,
            'symbols' => $this->symbols,
            default => throw new RuntimeException("unknown runtime table '{$name}'"),
        };
    }
}
