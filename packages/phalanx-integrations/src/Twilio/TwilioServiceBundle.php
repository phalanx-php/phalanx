<?php

declare(strict_types=1);

namespace Phalanx\Integration\Twilio;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class TwilioServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $twilioConfig = new TwilioConfig(
            accountSid: $context['twilio_account_sid'] ?? '',
            authToken: $context['twilio_auth_token'] ?? '',
        );

        $services->singleton(TwilioRest::class)
            ->factory(static function () use ($twilioConfig) {
                if ($twilioConfig->accountSid === '' || $twilioConfig->authToken === '') {
                    throw new \RuntimeException('TWILIO_ACCOUNT_SID and TWILIO_AUTH_TOKEN are required to use TwilioRest');
                }
                return new TwilioRest($twilioConfig);
            });

        $baseUrl = $context['base_url'] ?? getenv('BASE_URL') ?: '';

        $services->singleton(TwilioWebhook::class)
            ->factory(static fn() => new TwilioWebhook($twilioConfig, $baseUrl));
    }
}
