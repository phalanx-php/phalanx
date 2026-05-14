<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Task;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Grammata\NativeFastPath\NativeFastPath;
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
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new FilesystemException("Failed to read: {$this->path}", $this->path);
        }

        if ($this->maxBytes !== null) {
            $size = @filesize($this->path);
            if ($size !== false && $size > $this->maxBytes) {
                throw new RuntimeException(
                    "File exceeds read limit of {$this->maxBytes} bytes: {$this->path} ({$size} bytes)",
                );
            }
        }

        try {
            return (new NativeFastPath())->read($scope, $this->path);
        } catch (RuntimeException $e) {
            throw new FilesystemException("Failed to read: {$this->path}", $this->path, $e);
        }
    }
}
