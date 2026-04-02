<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Task;

use Phalanx\ExecutionScope;
use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Filesystem\FileInfo;
use Phalanx\Task\Executable;

final readonly class StatFile implements Executable
{
    public function __construct(
        private string $path,
    ) {}

    public function __invoke(ExecutionScope $scope): FileInfo
    {
        $stat = @stat($this->path);

        if ($stat === false) {
            throw new FilesystemException("Failed to stat: {$this->path}", $this->path);
        }

        return FileInfo::fromStat($this->path, $stat);
    }
}
