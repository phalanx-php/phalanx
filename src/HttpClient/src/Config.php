<?php

declare(strict_types=1);

namespace Phalanx\HttpClient;

use Phalanx\Config\Config as ConfigContract;
use Phalanx\Config\Env;
use Phalanx\Config\Issue;
use Phalanx\Config\ValidationContext;
use Phalanx\System\TlsOptions;

final class Config implements ConfigContract
{
    /** computed: validates that at least one HTTP timeout is configured. */
    public bool $configured {
        get => $this->connectTimeout > 0.0 || $this->readTimeout > 0.0;
    }

    public function __construct(
        #[Env(key: 'HTTP_CLIENT_CONNECT_TIMEOUT', description: 'HTTP client connect timeout in seconds')]
        private(set) float $connectTimeout = 5.0,
        #[Env(key: 'HTTP_CLIENT_READ_TIMEOUT', description: 'HTTP client read timeout in seconds')]
        private(set) float $readTimeout = 30.0,
        #[Env(key: 'HTTP_CLIENT_MAX_RESPONSE_BYTES', description: 'Maximum HTTP response body size in bytes')]
        private(set) int $maxResponseBytes = 16 * 1024 * 1024,
        #[Env(key: 'HTTP_CLIENT_USER_AGENT', description: 'HTTP client User-Agent header value')]
        private(set) ?string $userAgent = 'Phalanx-HttpClient/0.6',
        private(set) ?TlsOptions $tlsOptions = null,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [];
    }
}
