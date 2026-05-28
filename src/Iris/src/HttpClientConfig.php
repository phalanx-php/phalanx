<?php

declare(strict_types=1);

namespace Phalanx\Iris;

use Phalanx\System\TlsOptions;
use Phalanx\Themis\Config;
use Phalanx\Themis\Env;
use Phalanx\Themis\Issue;
use Phalanx\Themis\ValidationContext;

final class HttpClientConfig implements Config
{
    /** computed: validates that at least one HTTP timeout is configured. */
    public bool $configured {
        get => $this->connectTimeout > 0.0 || $this->readTimeout > 0.0;
    }

    public function __construct(
        #[Env(key: 'IRIS_CONNECT_TIMEOUT', description: 'HTTP client connect timeout in seconds')]
        private(set) float $connectTimeout = 5.0,

        #[Env(key: 'IRIS_READ_TIMEOUT', description: 'HTTP client read timeout in seconds')]
        private(set) float $readTimeout = 30.0,

        #[Env(key: 'IRIS_MAX_RESPONSE_BYTES', description: 'Maximum HTTP response body size in bytes')]
        private(set) int $maxResponseBytes = 16 * 1024 * 1024,

        #[Env(key: 'IRIS_USER_AGENT', description: 'HTTP client User-Agent header value')]
        private(set) ?string $userAgent = 'Phalanx-Iris/0.6',

        private(set) ?TlsOptions $tlsOptions = null,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [];
    }
}
