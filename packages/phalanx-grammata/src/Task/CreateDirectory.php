<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Task;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Task\Executable;

final readonly class CreateDirectory implements Executable
{
    public function __construct(
        private string $path,
        private bool $recursive = true,
        private int $permissions = 0755,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        if (is_dir($this->path)) {
            return null;
        }

        if (!@mkdir($this->path, $this->permissions, $this->recursive)) {
            throw new FilesystemException("Failed to create directory: {$this->path}", $this->path);
        }

        return null;
    }
}
