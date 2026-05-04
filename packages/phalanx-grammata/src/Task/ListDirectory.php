<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Task;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Task\Executable;

final readonly class ListDirectory implements Executable
{
    public function __construct(
        private string $path,
    ) {}

    /** @return list<string> */
    public function __invoke(ExecutionScope $scope): array
    {
        $entries = @scandir($this->path);

        if ($entries === false) {
            throw new FilesystemException("Failed to list directory: {$this->path}", $this->path);
        }

        return array_values(array_filter($entries, static fn(string $e) => $e !== '.' && $e !== '..'));
    }
}
