<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Task;

use Phalanx\ExecutionScope;
use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Filesystem\FilePool;
use Phalanx\Stream\Emitter;
use Phalanx\Task\Executable;
use React\Stream\ReadableResourceStream;

final readonly class ReadFileStream implements Executable
{
    public function __construct(
        private string $path,
    ) {}

    public function __invoke(ExecutionScope $scope): Emitter
    {
        $pool = $scope->service(FilePool::class);
        $pool->acquire($scope);

        $handle = @fopen($this->path, 'r');

        if ($handle === false) {
            $pool->release();
            throw new FilesystemException("Failed to open: {$this->path}", $this->path);
        }

        $stream = new ReadableResourceStream($handle);

        $scope->onDispose(static function () use ($stream, $pool): void {
            $stream->close();
            $pool->release();
        });

        $stream->on('close', static fn() => $pool->release());

        return Emitter::stream($stream);
    }
}
