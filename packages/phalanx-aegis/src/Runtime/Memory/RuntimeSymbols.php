<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

use OpenSwoole\Atomic\Long;
use OpenSwoole\Exception as OpenSwooleException;

final class RuntimeSymbols
{
    private Long $ids;

    /** @var array<int, string> */
    private array $local = [];

    public function __construct(private readonly ManagedSwooleTables $tables)
    {
        $this->ids = new Long();
    }

    public function idFor(string $kind, string $value): int
    {
        if ($value === '') {
            return 0;
        }

        $key = self::key($kind, $value);
        $row = $this->tables->symbols->get($key);
        if (is_array($row) && (int) $row['id'] > 0) {
            $id = (int) $row['id'];
            $this->local[$id] = (string) $row['value'];
            return $id;
        }

        $id = (int) $this->ids->add();
        $stored = self::fit($value, 512);
        try {
            $ok = $this->tables->symbols->set($key, [
                'id' => $id,
                'kind' => self::fit($kind, 32),
                'value' => $stored,
                'created_at' => microtime(true),
            ]);
        } catch (OpenSwooleException) {
            throw RuntimeMemoryCapacityExceeded::forTable('symbols', $key);
        }

        if (!$ok) {
            throw RuntimeMemoryCapacityExceeded::forTable('symbols', $key);
        }

        $this->tables->mark('symbols');
        $this->local[$id] = $stored;

        return $id;
    }

    public function valueFor(int $id, string $fallback = ''): string
    {
        if ($id < 1) {
            return $fallback;
        }

        if (isset($this->local[$id])) {
            return $this->local[$id];
        }

        foreach ($this->tables->symbols as $row) {
            if (!is_array($row) || (int) $row['id'] !== $id) {
                continue;
            }

            $value = (string) $row['value'];
            $this->local[$id] = $value;
            return $value;
        }

        return $fallback;
    }

    private static function key(string $kind, string $value): string
    {
        return substr(sha1($kind . "\0" . $value), 0, 32);
    }

    private static function fit(string $value, int $length): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length);
    }
}
