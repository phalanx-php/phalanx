<?php

declare(strict_types=1);

namespace Phalanx\Enigma;

final class TransferResult
{
    public function __construct(
        public readonly string $localPath,
        public readonly string $remotePath,
        public readonly int $bytesTransferred,
        public readonly float $durationMs,
    ) {}

    public float $throughputBytesPerSec {
        get => $this->durationMs > 0
            ? ($this->bytesTransferred / ($this->durationMs / 1000))
            : 0.0;
    }
}
