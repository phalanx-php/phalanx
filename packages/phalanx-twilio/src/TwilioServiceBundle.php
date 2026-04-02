<?php

declare(strict_types=1);

namespace Phalanx\Twilio;

use Phalanx\Suspendable;
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
            ->factory(static function (Suspendable $scope) use ($twilioConfig) {
                if ($twilioConfig->accountSid === '' || $twilioConfig->authToken === '') {
                    throw new \RuntimeException('TWILIO_ACCOUNT_SID and TWILIO_AUTH_TOKEN are required to use TwilioRest');
                }
                return new TwilioRest($twilioConfig, $scope);
            });

        $baseUrl = $context['base_url'] ?? $context['BASE_URL'] ?? '';

        $services->singleton(TwilioWebhook::class)
            ->factory(static fn() => new TwilioWebhook($twilioConfig, $baseUrl));
    }
}
