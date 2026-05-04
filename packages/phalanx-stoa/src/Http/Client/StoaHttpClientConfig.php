<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Http\Client;

use Phalanx\System\TlsOptions;

final readonly class StoaHttpClientConfig
{
    public function __construct(
        public float $connectTimeout = 5.0,
        public float $readTimeout = 30.0,
        public int $maxResponseBytes = 16 * 1024 * 1024,
        public ?string $userAgent = 'Phalanx-Stoa/0.2',
        public ?TlsOptions $tlsOptions = null,
    ) {
    }
}
