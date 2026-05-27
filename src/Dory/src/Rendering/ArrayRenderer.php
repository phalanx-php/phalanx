<?php

declare(strict_types=1);

namespace Phalanx\Dory\Rendering;

use Phalanx\Archon\Console\Widget\Table;

final class ArrayRenderer implements ValueRenderer
{
    private const int TERMINAL_WIDTH = 120;

    public function __construct(private(set) Table $table)
    {
    }

    public function supports(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }

    public function render(mixed $value, OutputSink $output): void
    {
        if ($this->isTabular($value)) {
            $this->renderTable($value, $output);
            return;
        }

        if (!array_is_list($value)) {
            $this->renderKeyValue($value, $output);
            return;
        }

        $this->renderList($value, $output);
    }

    /**
     * A tabular array is a sequential list where every element is an associative
     * array sharing the same key set. Empty inner arrays are excluded — they carry
     * no columns.
     *
     * @param array<mixed> $value
     */
    private function isTabular(array $value): bool
    {
        if (!array_is_list($value)) {
            return false;
        }

        $firstKeys = null;

        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) {
                return false;
            }

            $keys = array_keys($row);

            if ($firstKeys === null) {
                $firstKeys = $keys;
                continue;
            }

            if ($keys !== $firstKeys) {
                return false;
            }
        }

        return $firstKeys !== null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderTable(array $rows, OutputSink $output): void
    {
        /** @var list<string> $headers */
        $headers = array_keys($rows[0]);

        $sampleRows = array_values(array_map(
            static fn(array $row): array => array_values(
                array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : var_export($v, true), $row)
            ),
            $rows,
        ));

        $widths = Table::computeWidths($headers, $sampleRows, self::TERMINAL_WIDTH);

        $output->line($this->table->header($headers, $widths));

        foreach ($sampleRows as $row) {
            $output->line($this->table->row($row, $widths));
        }

        $output->line($this->table->footer($widths));
    }

    /**
     * @param array<string|int, mixed> $value
     */
    private function renderKeyValue(array $value, OutputSink $output): void
    {
        foreach ($value as $key => $v) {
            $display = is_scalar($v) ? (string) $v : var_export($v, true);
            $output->line("  {$key}: {$display}");
        }
    }

    /**
     * @param list<mixed> $value
     */
    private function renderList(array $value, OutputSink $output): void
    {
        foreach ($value as $i => $v) {
            $display = is_scalar($v) ? (string) $v : var_export($v, true);
            $output->line("  [{$i}] {$display}");
        }
    }
}
