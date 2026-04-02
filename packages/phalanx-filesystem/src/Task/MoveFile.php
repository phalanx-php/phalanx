<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Task;

use Phalanx\ExecutionScope;
use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Task\Executable;

final readonly class MoveFile implements Executable
{
    public function __construct(
        private string $from,
        private string $to,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        if (!@rename($this->from, $this->to)) {
            throw new FilesystemException("Failed to move {$this->from} to {$this->to}", $this->from);
        }

        return null;
    }
}
