<?php

declare(strict_types=1);

namespace Phalanx\Twilio;

final class TwilioApiException extends \RuntimeException
{
    public function __construct(
        private(set) int $statusCode,
        private(set) string $responseBody,
    ) {
        parent::__construct("Twilio API error: HTTP {$statusCode}");
    }
}
