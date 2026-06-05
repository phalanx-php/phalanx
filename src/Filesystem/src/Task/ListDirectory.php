<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Task;

use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

final readonly class ListDirectory implements Executable
{
    public function __construct(
        private string $path,
    ) {
    }

    /** @return list<string> */
    public function __invoke(ExecutionScope $scope): array
    {
        try {
            $iterator = new \DirectoryIterator($this->path);
        } catch (\UnexpectedValueException $e) {
            throw new FilesystemException("Failed to list directory: {$this->path}", $this->path, $e);
        }

        $entries = [];

        foreach ($iterator as $entry) {
            if (!$entry->isDot()) {
                $entries[] = $entry->getFilename();
            }
        }

        return $entries;
    }
}
