<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Task;

use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Grammata\FilePool;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Emitter;
use Phalanx\Task\Executable;

final readonly class ReadFileStream implements Executable
{
    private const int CHUNK_BYTES = 8192;

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

        $scope->onDispose(static function () use ($handle, $pool): void {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $pool->release();
        });

        $path = $this->path;

        return Emitter::produce(static function (Channel $ch, ExecutionScope $ctx) use ($handle, $path): void {
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
        });
    }
}
