<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Support;

final readonly class RuntimeEvents
{
    public function __construct(private string $path)
    {
    }

    /** @param array<string, mixed> $context */
    public function record(string $event, array $context = []): void
    {
        file_put_contents(
            $this->path,
            json_encode(
                ['event' => $event, 'context' => $context, 'at' => microtime(true)],
                JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }

    /** @return list<array{event: string, context: array<string, mixed>, at: float}> */
    public function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $events = [];
        foreach (file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && isset($decoded['event'], $decoded['context'], $decoded['at'])) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    public function contains(string $event): bool
    {
        foreach ($this->all() as $entry) {
            if ($entry['event'] === $event) {
                return true;
            }
        }

        return false;
    }
}
