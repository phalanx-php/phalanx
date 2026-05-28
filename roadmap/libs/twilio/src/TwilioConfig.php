<?php

declare(strict_types=1);

namespace Phalanx\Twilio;

final class TwilioConfig
{
    public function __construct(
        private(set) string $accountSid,
        private(set) string $authToken,
        private(set) string $apiBase = 'https://api.twilio.com/2010-04-01',
    ) {}
}
