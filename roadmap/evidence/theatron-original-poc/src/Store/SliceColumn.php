<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

use OpenSwoole\Table;

final class SliceColumn
{
    public int $tableSize {
        get => $this->type === 'string' ? 2048 : 8;
    }

    public int $tableType {
        get => match ($this->type) {
            'int', 'bool' => Table::TYPE_INT,
            'float' => Table::TYPE_FLOAT,
            'string' => Table::TYPE_STRING,
        };
    }

    public function __construct(
        private(set) string $name,
        private(set) string $type,
        private(set) bool $nullable,
    ) {
    }
}
