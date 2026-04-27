<?php

declare(strict_types=1);

namespace Phalanx\Trace;

final class Trace
{
    /** @var list<TraceEntry> */
    private array $entries = [];

    private float $requestStartMs;

    private int $sampleCounter = 0;
    private int $concurrentDepth = 0;
    private int $servicesCreated = 0;
    private int $peakMemoryBytes = 0;
    private int $lastMemoryBytes = 0;
    private int $servicesDisposed = 0;

    private readonly string $applicationPath;

    public function __construct(
        private readonly bool $enabled = true,
        ?string $applicationPath = null,
    ) {
        $this->requestStartMs = hrtime(true) / 1e6;
        $this->lastMemoryBytes = memory_get_usage(true);
        $this->applicationPath = $applicationPath ?? (getcwd() ?: "");
    }

    /** @param array<string, mixed> $context */
    public static function fromContext(array $context, ?string $applicationPath = null): self
    {
        $envValue = $context["PHALANX_TRACE"] ?? false;
        $enabled = !in_array($envValue, [false, "", "0"], true);
        return new self($enabled, $applicationPath);
    }

    public function reset(): void
    {
        $this->entries = [];
        $this->sampleCounter = 0;
        $this->concurrentDepth = 0;
        $this->servicesCreated = 0;
        $this->servicesDisposed = 0;
        $this->requestStartMs = hrtime(true) / 1e6;
        $this->lastMemoryBytes = memory_get_usage(true);
        $this->peakMemoryBytes = $this->lastMemoryBytes;
    }

    /** @param array<string, mixed> $extra */
    public function log(
        TraceType $type,
        string $subject,
        array $extra = [],
        ?object $task = null,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $now = hrtime(true) / 1e6;
        $timestampMs = $now - $this->requestStartMs;

        $durationMs = $extra["elapsed"] ?? ($extra["duration"] ?? null);
        $error = $extra["error"] ?? null;

        if ($type === TraceType::ConcurrentStart) {
            $this->concurrentDepth++;
        }

        if ($type === TraceType::ServiceInit) {
            $this->servicesCreated++;
        }

        if ($type === TraceType::ServiceDispose) {
            $this->servicesDisposed++;
        }

        $memoryBytes = null;
        if (++$this->sampleCounter % 10 === 0) {
            $memoryBytes = memory_get_usage(true);
            if ($memoryBytes > $this->peakMemoryBytes) {
                $this->peakMemoryBytes = $memoryBytes;
            }
            $this->lastMemoryBytes = $memoryBytes;
        }

        $rawFrames = null;
        $capturedTask = null;

        if ($type === TraceType::Executing || $type === TraceType::ServiceInit) {
            $rawFrames = $this->captureFrames();
            $capturedTask = $task;
        }

        $this->entries[] = new TraceEntry(
            type: $type,
            subject: $subject,
            timestampMs: $timestampMs,
            depth: $this->concurrentDepth,
            durationMs: $durationMs,
            error: $error,
            memoryBytes: $memoryBytes,
            fiberId: null,
            rawFrames: $rawFrames,
            task: $capturedTask,
            applicationPath: $this->applicationPath,
        );

        if ($type === TraceType::ConcurrentEnd) {
            $this->concurrentDepth = max(0, $this->concurrentDepth - 1);
        }
    }

    public function print(): void
    {
        if (!$this->enabled || $this->entries === []) {
            return;
        }

        $totalMs = hrtime(true) / 1e6 - $this->requestStartMs;

        echo "\n";

        foreach ($this->entries as $entry) {
            $this->printEntry($entry);
        }

        echo "\n";
        $this->printFooter($totalMs);
    }

    /** @return list<TraceEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function clear(): void
    {
        $this->reset();
    }

    public function startTime(): float
    {
        return $this->requestStartMs;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(
            fn(TraceEntry $e): array => [
                "args" => $e->args(),
                "depth" => $e->depth,
                "error" => $e->error,
                "subject" => $e->subject,
                "type" => $e->type->value,
                "location" => $e->location(),
                "durationMs" => $e->durationMs,
                "timestampMs" => $e->timestampMs,
                "memoryBytes" => $e->memoryBytes,
            ],
            $this->entries,
        );
    }

    /** @return list<object> */
    private function captureFrames(): array
    {
        if (class_exists(\Spatie\Backtrace\Backtrace::class)) {
            return \Spatie\Backtrace\Backtrace::create()->limit(10)->frames();
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        return array_map(
            fn(array $frame) => (object) [
                "file" => $frame["file"] ?? "unknown",
                "lineNumber" => $frame["line"] ?? 0,
            ],
            $trace,
        );
    }

    private function printEntry(TraceEntry $entry): void
    {
        $time = $this->formatTime($entry->timestampMs);
        $type = $entry->type->value;
        $indent = str_repeat("  ", $entry->depth);
        $subject = $entry->subject;

        $args = $entry->args() ? " " . $entry->args() : "";
        $parts = ["$time  $type  $indent$subject$args"];

        if ($entry->memoryBytes !== null) {
            $parts[] = $this->formatBytes($entry->memoryBytes);
        }

        if ($entry->durationMs !== null) {
            $parts[] = "+" . $this->formatDuration($entry->durationMs);
        }

        if ($entry->error) {
            $parts[] = "[{$entry->error}]";
        }

        $location = $entry->location();
        if ($location) {
            $parts[] = $location;
        }

        printf("%s\n", implode('  ', $parts));
    }

    private function printFooter(float $totalMs): void
    {
        printf(
            "%d svc  %s peak  %d gc  %s total\n",
            $this->servicesCreated,
            $this->formatBytes($this->peakMemoryBytes),
            gc_status()["runs"],
            $this->formatDuration($totalMs),
        );
    }

    private function formatTime(float $ms): string
    {
        if ($ms >= 1000) {
            return sprintf("%5.1fs", $ms / 1000);
        }
        return sprintf("%5.0fms", $ms);
    }

    private function formatDuration(float $ms): string
    {
        if ($ms >= 1000) {
            return sprintf("%.1fs", $ms / 1000);
        }
        if ($ms >= 100) {
            return sprintf("%.0fms", $ms);
        }
        if ($ms >= 10) {
            return sprintf("%.1fms", $ms);
        }
        return sprintf("%.2fms", $ms);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return sprintf("%.1fMB", $bytes / 1024 / 1024);
        }
        if ($bytes >= 1024) {
            return sprintf("%.0fKB", $bytes / 1024);
        }
        return $bytes . "B";
    }
}
