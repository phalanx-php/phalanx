<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Task;

use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Filesystem\FilePool;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Stream\Channel;
use Phalanx\Stream\Emitter;
use Phalanx\Task\Executable;

final readonly class ReadFileStream implements Executable
{
    private const int CHUNK_BYTES = 8192;

    public function __construct(
        private string $path,
    ) {
    }

    public function __invoke(ExecutionScope $scope): Emitter
    {
        $path = $this->path;

        return Emitter::produce(static function (Channel $ch, ExecutionScope $ctx) use ($path): void {
            $pool = $ctx->service(FilePool::class);
            $pool->acquire($ctx);
            $handle = is_file($path) && is_readable($path) ? @fopen($path, 'r') : false;

            try {
                if ($handle === false) {
                    throw new FilesystemException("Failed to open: {$path}", $path);
                }

                while (!feof($handle)) {
                    $ctx->throwIfCancelled();
                    $chunk = @fread($handle, self::CHUNK_BYTES);
                    if ($chunk === false) {
                        throw new FilesystemException("Read failed: {$path}", $path);
                    }
                    if ($chunk === '') {
                        break;
                    }
                    $ch->emit($chunk);
                }
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                $pool->release();
            }
        });
    }
}
