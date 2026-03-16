<?php

declare(strict_types=1);

namespace Convoy\Trace;

final class TraceEntry
{
    private ?string $resolvedLocation = null;
    private ?string $resolvedArgs = null;

    public function __construct(
        public readonly TraceType $type,
        public readonly string $subject,
        public readonly float $timestampMs,
        public readonly int $depth = 0,
        public readonly ?float $durationMs = null,
        public readonly ?string $error = null,
        public readonly ?int $memoryBytes = null,
        public readonly ?int $fiberId = null,
        /** @var list<object>|null */
        private readonly ?array $rawFrames = null,
        /** @var object|null */
        private readonly ?object $task = null,
        private readonly ?string $applicationPath = null,
    ) {
    }

    public function location(): ?string
    {
        if ($this->resolvedLocation !== null) {
            return $this->resolvedLocation;
        }

        if ($this->rawFrames === null) {
            return null;
        }

        $frame = $this->findRelevantFrame();
        if ($frame === null) {
            return null;
        }

        /** @var string $file */
        $file = $frame->file ?? 'unknown';
        if ($this->applicationPath && str_starts_with($file, $this->applicationPath)) {
            $file = substr($file, strlen($this->applicationPath) + 1);
        }

        /** @var int $lineNumber */
        $lineNumber = $frame->lineNumber ?? 0;
        $this->resolvedLocation = "$file:$lineNumber";
        return $this->resolvedLocation;
    }

    public function args(): ?string
    {
        if ($this->resolvedArgs !== null) {
            return $this->resolvedArgs;
        }

        if ($this->task === null) {
            return null;
        }

        $this->resolvedArgs = $this->extractTaskArgs();
        return $this->resolvedArgs;
    }

    private function findRelevantFrame(): ?object
    {
        if ($this->rawFrames === null || $this->rawFrames === []) {
            return null;
        }

        foreach ($this->rawFrames as $frame) {
            $file = (string) ($frame->file ?? '');

            if ($file === '' || $file === 'unknown') {
                continue;
            }
            if (str_contains($file, 'Trace.php')) {
                continue;
            }
            if (str_contains($file, 'Core.php')) {
                continue;
            }
            if (str_contains($file, '/Services/')) {
                continue;
            }
            if (str_contains($file, '/Http/')) {
                continue;
            }
            if (str_contains($file, 'vendor/')) {
                continue;
            }

            return $frame;
        }

        return null;
    }

    private function extractTaskArgs(): string
    {
        $task = $this->task;

        if ($task === null || $task instanceof \Closure) {
            return '';
        }

        $ref = new \ReflectionClass($task);
        $props = $ref->getProperties(
            \ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE
        );

        $parts = [];
        foreach ($props as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            if (!$prop->isInitialized($task)) {
                continue;
            }
            $value = $prop->getValue($task);
            $formatted = $this->formatArgValue($value);

            if ($formatted !== null) {
                $parts[] = $prop->getName() . ':' . $formatted;
            }

            if (count($parts) >= 4) {
                break;
            }
        }

        return $parts === [] ? '' : '{' . implode(',', $parts) . '}';
    }

    private function formatArgValue(mixed $value): ?string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return sprintf('%.2f', $value);
        }

        if (is_string($value)) {
            if (strlen($value) > 15) {
                return '"' . substr($value, 0, 12) . '..."';
            }
            return '"' . $value . '"';
        }

        if (is_array($value)) {
            return '[' . count($value) . ']';
        }

        if (is_object($value)) {
            $parts = explode('\\', $value::class);
            return end($parts);
        }

        return null;
    }
}
