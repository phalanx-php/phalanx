<?php

declare(strict_types=1);

namespace Phalanx\Iris;

use Phalanx\System\TlsOptions;

final readonly class HttpClientConfig
{
    public function __construct(
        public float $connectTimeout = 5.0,
        public float $readTimeout = 30.0,
        public int $maxResponseBytes = 16 * 1024 * 1024,
        public ?string $userAgent = 'Phalanx-Iris/0.6',
        public ?TlsOptions $tlsOptions = null,
    ) {
    }
}
