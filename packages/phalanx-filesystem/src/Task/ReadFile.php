<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Task;

use Phalanx\ExecutionScope;
use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Task\Executable;

final readonly class ReadFile implements Executable
{
    public function __construct(
        private string $path,
    ) {}

    public function __invoke(ExecutionScope $scope): string
    {
        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            throw new FilesystemException("Failed to read: {$this->path}", $this->path);
        }

        return $contents;
    }
}
