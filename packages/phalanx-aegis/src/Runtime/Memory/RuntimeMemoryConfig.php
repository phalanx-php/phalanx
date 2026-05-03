<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

use InvalidArgumentException;

final readonly class RuntimeMemoryConfig
{
    public const CONTEXT_KEY = 'phalanx.runtime.memory';

    public function __construct(
        public int $resourceRows = 4096,
        public int $edgeRows = 8192,
        public int $leaseRows = 4096,
        public int $annotationRows = 8192,
        public int $eventRows = 4096,
        public int $counterRows = 1024,
        public int $claimRows = 1024,
        public int $symbolRows = 2048,
        public string $projectPath = '',
    ) {
        foreach (
            [
            'resourceRows' => $this->resourceRows,
            'edgeRows' => $this->edgeRows,
            'leaseRows' => $this->leaseRows,
            'annotationRows' => $this->annotationRows,
            'eventRows' => $this->eventRows,
            'counterRows' => $this->counterRows,
            'claimRows' => $this->claimRows,
            'symbolRows' => $this->symbolRows,
            ] as $name => $rows
        ) {
            if ($rows < 1) {
                throw new InvalidArgumentException("{$name} must be greater than zero.");
            }
        }
    }

    /** @param array<string, mixed> $context */
    public static function fromContext(array $context): self
    {
        $raw = $context[self::CONTEXT_KEY] ?? [];
        if (!is_array($raw)) {
            throw new InvalidArgumentException(self::CONTEXT_KEY . ' must be an array.');
        }

        return new self(
            resourceRows: self::intOption($raw, 'resource_rows', self::legacyResourceRows($raw)),
            edgeRows: self::intOption($raw, 'edge_rows', 8192),
            leaseRows: self::intOption($raw, 'lease_rows', 4096),
            annotationRows: self::intOption($raw, 'annotation_rows', 8192),
            eventRows: self::intOption($raw, 'event_rows', 4096),
            counterRows: self::intOption($raw, 'counter_rows', 1024),
            claimRows: self::intOption($raw, 'claim_rows', 1024),
            symbolRows: self::intOption($raw, 'symbol_rows', 2048),
            projectPath: self::stringOption($raw, 'project_path', ''),
        );
    }

    public static function forLedgerSize(int $rows): self
    {
        return new self(
            resourceRows: max(16, $rows * 2),
            edgeRows: max(16, $rows * 2),
            leaseRows: max(16, $rows * 2),
            annotationRows: max(16, $rows * 4),
            eventRows: max(16, $rows * 4),
            counterRows: max(16, $rows),
            claimRows: max(16, $rows),
            symbolRows: max(16, $rows * 2),
        );
    }

    /** @param array<string, mixed> $options */
    private static function intOption(array $options, string $key, int $default): int
    {
        $value = $options[$key] ?? $default;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new InvalidArgumentException(self::CONTEXT_KEY . ".{$key} must be an integer.");
    }

    /** @param array<string, mixed> $options */
    private static function legacyResourceRows(array $options): int
    {
        $scopeRows = $options['scope_rows'] ?? 1024;
        $runRows = $options['run_rows'] ?? 4096;

        if (is_string($scopeRows) && ctype_digit($scopeRows)) {
            $scopeRows = (int) $scopeRows;
        }
        if (is_string($runRows) && ctype_digit($runRows)) {
            $runRows = (int) $runRows;
        }
        if (!is_int($scopeRows) || !is_int($runRows)) {
            return 4096;
        }

        return max($scopeRows + $runRows, 1);
    }

    /** @param array<string, mixed> $options */
    private static function stringOption(array $options, string $key, string $default): string
    {
        $value = $options[$key] ?? $default;
        if (is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException(self::CONTEXT_KEY . ".{$key} must be a string.");
    }
}
