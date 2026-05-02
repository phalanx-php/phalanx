<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Advanced\Services;

final class AuditLog
{
    public int $count {
        get => count($this->events);
    }

    /** @var list<array{method: string, path: string}> */
    private array $events = [];

    public function record(string $method, string $path): void
    {
        $this->events[] = [
            'method' => $method,
            'path' => $path,
        ];
    }
}
