<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Task;

use Phalanx\ExecutionScope;
use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Task\Executable;

final readonly class AppendFile implements Executable
{
    public function __construct(
        private string $path,
        private string $contents,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        $bytes = @file_put_contents($this->path, $this->contents, FILE_APPEND);

        if ($bytes === false) {
            throw new FilesystemException("Failed to append to: {$this->path}", $this->path);
        }

        return null;
    }
}
