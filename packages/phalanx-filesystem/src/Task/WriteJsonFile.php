<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Task;

use Phalanx\ExecutionScope;
use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Task\Executable;

final readonly class WriteJsonFile implements Executable
{
    public function __construct(
        private string $path,
        private mixed $data,
        private int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        $json = json_encode($this->data, $this->flags);

        if ($json === false) {
            throw new FilesystemException("Failed to encode JSON for {$this->path}", $this->path);
        }

        return $scope->execute(new WriteFile($this->path, $json . "\n"));
    }
}
