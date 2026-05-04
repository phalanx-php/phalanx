<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Task;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Task\Executable;

final readonly class WriteFile implements Executable
{
    public function __construct(
        private string $path,
        private string $contents,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        $bytes = @file_put_contents($this->path, $this->contents);

        if ($bytes === false) {
            throw new FilesystemException("Failed to write: {$this->path}", $this->path);
        }

        return null;
    }
}
