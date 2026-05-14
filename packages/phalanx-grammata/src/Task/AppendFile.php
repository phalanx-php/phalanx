<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Task;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Grammata\NativeFastPath\NativeFastPath;
use Phalanx\Task\Executable;
use RuntimeException;

final readonly class AppendFile implements Executable
{
    public function __construct(
        private string $path,
        private string $contents,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        if (!self::canAppend($this->path)) {
            throw new FilesystemException("Failed to append to: {$this->path}", $this->path);
        }

        try {
            (new NativeFastPath())->write($scope, $this->path, $this->contents, FILE_APPEND);
        } catch (RuntimeException $e) {
            throw new FilesystemException("Failed to append to: {$this->path}", $this->path, $e);
        }

        return null;
    }

    private static function canAppend(string $path): bool
    {
        if (is_file($path)) {
            return is_writable($path);
        }

        $directory = dirname($path);

        return is_dir($directory) && is_writable($directory);
    }
}
