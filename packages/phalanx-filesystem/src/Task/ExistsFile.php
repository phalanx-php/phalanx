<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Task;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

final readonly class ExistsFile implements Executable
{
    public function __construct(
        private string $path,
    ) {}

    public function __invoke(ExecutionScope $scope): bool
    {
        return file_exists($this->path);
    }
}
