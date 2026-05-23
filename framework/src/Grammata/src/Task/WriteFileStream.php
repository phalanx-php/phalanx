<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Task;

use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Grammata\FilePool;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Styx\Emitter;
use Phalanx\Task\Executable;

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

        try {
            if ($handle === false) {
                throw new FilesystemException("Failed to open for writing: {$this->path}", $this->path);
            }

            foreach (($this->source)($scope) as $chunk) {
                $scope->throwIfCancelled();
                if (!is_string($chunk)) {
                    throw new FilesystemException(
                        'Stream emitted non-string chunk: ' . get_debug_type($chunk),
                        $this->path,
                    );
                }
                if (@fwrite($handle, $chunk) === false) {
                    throw new FilesystemException("Write failed: {$this->path}", $this->path);
                }
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $pool->release();
        }

        return null;
    }
}
