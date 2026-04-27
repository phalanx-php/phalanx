<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Task;

use Phalanx\ExecutionScope;
use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Grammata\FilePool;
use Phalanx\Styx\Emitter;
use Phalanx\Task\Executable;
use React\Stream\WritableResourceStream;

final readonly class WriteFileStream implements Executable
{
    public function __construct(
        private string $path,
        private Emitter $source,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        $pool = $scope->service(FilePool::class);
        $pool->acquire($scope);

        $handle = @fopen($this->path, 'w');

        if ($handle === false) {
            $pool->release();
            throw new FilesystemException("Failed to open for writing: {$this->path}", $this->path);
        }

        $writable = new WritableResourceStream($handle);

        $scope->onDispose(static function () use ($writable, $pool): void {
            $writable->close();
            $pool->release();
        });

        $this->source->drain($scope, static fn(string $chunk) => $writable->write($chunk));
        $writable->end();
        $pool->release();

        return null;
    }
}
