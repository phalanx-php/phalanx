<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Task;

use Phalanx\ExecutionScope;
use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Task\Executable;

final readonly class ReadJsonFile implements Executable
{
    public function __construct(
        private string $path,
        private bool $assoc = true,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        $contents = $scope->execute(new ReadFile($this->path));

        try {
            return json_decode($contents, $this->assoc, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FilesystemException("Invalid JSON in {$this->path}: {$e->getMessage()}", $this->path, $e);
        }
    }
}
