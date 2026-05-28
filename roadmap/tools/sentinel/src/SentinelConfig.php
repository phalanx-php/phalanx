<?php

declare(strict_types=1);

namespace Sentinel;

final readonly class SentinelConfig
{
    public function __construct(
        public string $projectRoot,
        public string $dossierDir,
        public string $errorLog,
        public float $debounce = 0.5,
    ) {}
}
