<?php

declare(strict_types=1);

namespace Phalanx\Grammata;

use Phalanx\Grammata\Task\AppendFile;
use Phalanx\Grammata\Task\CreateDirectory;
use Phalanx\Grammata\Task\DeleteFile;
use Phalanx\Grammata\Task\ExistsFile;
use Phalanx\Grammata\Task\ListDirectory;
use Phalanx\Grammata\Task\MoveFile;
use Phalanx\Grammata\Task\ReadFile;
use Phalanx\Grammata\Task\ReadFileStream;
use Phalanx\Grammata\Task\ReadJsonFile;
use Phalanx\Grammata\Task\StatFile;
use Phalanx\Grammata\Task\WriteFile;
use Phalanx\Grammata\Task\WriteFileStream;
use Phalanx\Grammata\Task\WriteJsonFile;
use Phalanx\Styx\Emitter;
use Phalanx\TaskScope;

final class Files
{
    public function __construct(
        private readonly TaskScope $scope,
    ) {}

    public function read(string $path): string
    {
        return $this->scope->execute(new ReadFile($path));
    }

    public function readJson(string $path, bool $assoc = true): mixed
    {
        return $this->scope->execute(new ReadJsonFile($path, $assoc));
    }

    public function write(string $path, string $contents): void
    {
        $this->scope->execute(new WriteFile($path, $contents));
    }

    public function writeJson(string $path, mixed $data, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR): void
    {
        $this->scope->execute(new WriteJsonFile($path, $data, $flags));
    }

    public function append(string $path, string $contents): void
    {
        $this->scope->execute(new AppendFile($path, $contents));
    }

    public function readStream(string $path): Emitter
    {
        return $this->scope->execute(new ReadFileStream($path));
    }

    public function writeStream(string $path, Emitter $source): void
    {
        $this->scope->execute(new WriteFileStream($path, $source));
    }

    public function stat(string $path): FileInfo
    {
        return $this->scope->execute(new StatFile($path));
    }

    public function exists(string $path): bool
    {
        return $this->scope->execute(new ExistsFile($path));
    }

    public function delete(string $path): void
    {
        $this->scope->execute(new DeleteFile($path));
    }

    public function move(string $from, string $to): void
    {
        $this->scope->execute(new MoveFile($from, $to));
    }

    public function mkdir(string $path, bool $recursive = true, int $permissions = 0755): void
    {
        $this->scope->execute(new CreateDirectory($path, $recursive, $permissions));
    }

    /** @return list<string> */
    public function listDir(string $path): array
    {
        return $this->scope->execute(new ListDirectory($path));
    }
}
