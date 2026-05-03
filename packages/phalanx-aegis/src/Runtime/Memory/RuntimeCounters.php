<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

final class RuntimeCounters
{
    public function __construct(private readonly ManagedSwooleTables $tables)
    {
    }

    private static function key(string $name): string
    {
        return substr(sha1($name), 0, 32);
    }

    public function incr(string $name, int $by = 1): int
    {
        $key = self::key($name);
        $value = $this->tables->counters->incr($key, 'value', $by);
        $this->tables->counters->set($key, [
            'value' => $value,
            'updated_at' => microtime(true),
        ]);
        $this->tables->mark('counters');

        return $value;
    }

    public function decr(string $name, int $by = 1): int
    {
        $key = self::key($name);
        $value = $this->tables->counters->decr($key, 'value', $by);
        $this->tables->counters->set($key, [
            'value' => $value,
            'updated_at' => microtime(true),
        ]);
        $this->tables->mark('counters');

        return $value;
    }

    public function get(string $name): int
    {
        $row = $this->tables->counters->get(self::key($name));

        return is_array($row) ? (int) $row['value'] : 0;
    }
}
