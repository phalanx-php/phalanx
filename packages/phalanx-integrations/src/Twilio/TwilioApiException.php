<?php

declare(strict_types=1);

namespace Phalanx\Integration\Twilio;

final class TwilioApiException extends \RuntimeException
{
    public function __construct(
        public private(set) int $statusCode,
        public private(set) string $responseBody,
    ) {
        parent::__construct("Twilio API error: HTTP {$statusCode}");
    }
}
