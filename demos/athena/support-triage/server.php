<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Acme\TriageHandler;
use Phalanx\Athena\AiServiceBundle;
use Phalanx\Demos\Athena\Support\DemoContextKeys;
use Phalanx\Stoa\Stoa;

return static fn(array $context): \Closure => static function () use ($context): int {
    $listen = $context['argv'][1] ?? '0.0.0.0:8080';
    $effective = DemoContextKeys::effectiveContext($context);

    return Stoa::starting($effective->values)
        ->providers(new AiServiceBundle())
        ->routes(['POST /triage' => TriageHandler::class])
        ->listen($listen)
        ->withBanner(<<<'BANNER'
            Support Triage Server
            Listening on {url}

            Endpoint:
              POST {url}/triage

            Example JSON:
              {"ticket_id":123,"customer_email":"sarah@example.com","subject":"Athena exhibit notes","body":"The owl symbolism section needs clearer source guidance."}

            Run with a hosted provider:
              ATHENA_DEMO_LIVE=1 ANTHROPIC_API_KEY=... composer demo:athena:serve:support-triage
            BANNER)
        ->run();
};
