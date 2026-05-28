<?php

declare(strict_types=1);

namespace Sentinel\Watcher;

final readonly class FileChange
{
    public function __construct(
        public string $path,
        public ChangeKind $kind,
        public float $timestamp,
        public ?string $diff = null,
    ) {}

    public function summary(): string
    {
        return "[{$this->kind->value}] {$this->path}";
    }

    public function isCode(): bool
    {
        $ext = pathinfo($this->path, PATHINFO_EXTENSION);

        return in_array($ext, ['php', 'ts', 'tsx', 'js', 'jsx', 'json', 'yaml', 'yml', 'neon', 'xml'], true);
    }
}