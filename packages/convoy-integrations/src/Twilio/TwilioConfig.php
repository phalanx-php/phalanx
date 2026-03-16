<?php

declare(strict_types=1);

namespace Convoy\Integration\Twilio;

final class TwilioConfig
{
    public function __construct(
        public private(set) string $accountSid,
        public private(set) string $authToken,
        public private(set) string $apiBase = 'https://api.twilio.com/2010-04-01',
    ) {}
}
