<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Task;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Task\Executable;

final readonly class DeleteFile implements Executable
{
    public function __construct(
        private string $path,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        if (!@unlink($this->path)) {
            throw new FilesystemException("Failed to delete: {$this->path}", $this->path);
        }

        return null;
    }
}
