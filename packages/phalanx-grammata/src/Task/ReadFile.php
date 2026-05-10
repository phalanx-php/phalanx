<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Task;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Task\Executable;
use RuntimeException;

// Sync reads do not go through FilePool; pool acquisition is reserved for
// streaming tasks where long-lived open handles must be bounded.
final readonly class ReadFile implements Executable
{
    public function __construct(
        private string $path,
        private ?int $maxBytes = null,
    ) {}

    public function __invoke(ExecutionScope $scope): string
    {
        if ($this->maxBytes !== null) {
            $size = @filesize($this->path);
            if ($size !== false && $size > $this->maxBytes) {
                throw new RuntimeException(
                    "File exceeds read limit of {$this->maxBytes} bytes: {$this->path} ({$size} bytes)",
                );
            }
        }

        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            throw new FilesystemException("Failed to read: {$this->path}", $this->path);
        }

        return $contents;
    }
}
