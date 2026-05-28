<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

use OpenSwoole\Table;

final class SliceTable
{
    private Table $table;

    public function __construct(
        private(set) SliceSchema $schema,
    ) {
        $this->table = new Table(1);
        foreach ($this->schema->columns as $column) {
            if ($column->nullable) {
                $this->table->column($column->name . '__present', Table::TYPE_INT, 1);
            }

            $this->table->column($column->name, $column->tableType, $column->tableSize);
        }

        if (!$this->table->create()) {
            throw new StoreException("Unable to create Swoole Table for {$this->schema->class}.");
        }

        $this->write($this->schema->initial());
    }

    public function read(): Slice
    {
        $row = $this->table->get($this->schema->key);
        if (!is_array($row)) {
            return $this->schema->initial();
        }

        return $this->schema->hydrate($row);
    }

    public function write(Slice $slice): void
    {
        $this->table->set($this->schema->key, $this->schema->encode($slice));
    }

    public function matches(Slice $left, Slice $right): bool
    {
        return $this->schema->encode($left) === $this->schema->encode($right);
    }
}
