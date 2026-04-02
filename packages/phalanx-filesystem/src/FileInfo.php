<?php

declare(strict_types=1);

namespace Phalanx\Filesystem;

final readonly class FileInfo
{
    public function __construct(
        public string $path,
        public int $size,
        public int $modifiedAt,
        public int $accessedAt,
        public int $createdAt,
        public int $permissions,
        public bool $isFile,
        public bool $isDirectory,
        public bool $isSymlink,
        public ?string $symlinkTarget = null,
    ) {}

    public static function fromStat(string $path, array $stat): self
    {
        return new self(
            path: $path,
            size: $stat['size'],
            modifiedAt: $stat['mtime'],
            accessedAt: $stat['atime'],
            createdAt: $stat['ctime'],
            permissions: $stat['mode'] & 0777,
            isFile: ($stat['mode'] & 0100000) !== 0,
            isDirectory: ($stat['mode'] & 0040000) !== 0,
            isSymlink: is_link($path),
            symlinkTarget: is_link($path) ? readlink($path) ?: null : null,
        );
    }
}
