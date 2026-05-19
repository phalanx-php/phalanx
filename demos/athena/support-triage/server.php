<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Acme\Tools\CheckServiceStatus;
use Acme\Tools\GetRecentTickets;
use Acme\Tools\LookupCustomer;
use Acme\Tools\SearchKnowledgeBase;
use Acme\TriageHandler;
use Phalanx\Athena\AthenaBundle;
use Phalanx\Athena\Router\SingleProviderRouter;
use Phalanx\Athena\Tool\ToolBundle;
use Phalanx\Demos\Athena\Support\DemoContextKeys;
use Phalanx\Demos\Kit\DemoProvider;
use Phalanx\Demos\Kit\FakeCueScript;
use Phalanx\Stoa\Stoa;

return static fn(array $context): \Closure => static function () use ($context): int {
    $listen   = $context['argv'][1] ?? '0.0.0.0:8080';
    $effective = DemoContextKeys::effectiveContext($context);

    $fakeScript = FakeCueScript::tokens(
        'Priority: medium. Category: feature-request. ' .
        'Knowledge base articles found. Issue can be resolved from existing documentation.',
        activityId: 'support-triage-demo',
        agentId: 'support-triage-agent',
    );

    $baseUrl = (string) ($effective->get(DemoContextKeys::OLLAMA_BASE_URL) ?? DemoProvider::OLLAMA_BASE);
    $model   = (string) ($effective->get(DemoContextKeys::OLLAMA_MODEL) ?? 'qwen2.5-coder:7b');
    $enabled = $effective->get(DemoContextKeys::OLLAMA_ENABLED, '1') !== '0';

    $choice = $enabled
        ? DemoProvider::ollamaOrFake($fakeScript, $model, $baseUrl)
        : DemoProvider::fakeOnly($fakeScript);

    $toolBundle = (new ToolBundle())
        ->add('lookup_customer', LookupCustomer::class)
        ->add('search_knowledge_base', SearchKnowledgeBase::class)
        ->add('get_recent_tickets', GetRecentTickets::class)
        ->add('check_service_status', CheckServiceStatus::class);

    $bundle = new AthenaBundle(
        router: new SingleProviderRouter($choice->provider),
        toolBundles: [$toolBundle],
    );

    return Stoa::starting($effective->values)
        ->providers($bundle)
        ->routes(['POST /triage' => TriageHandler::class])
        ->listen($listen)
        ->withBanner(sprintf(
            "Support Triage Server\n" .
            "Listening on {url}\n" .
            "Provider: %s\n\n" .
            "Endpoint:\n" .
            "  POST {url}/triage\n\n" .
            "Example JSON:\n" .
            "  {\"customer_email\":\"hoplite@sparta.polis\",\"subject\":\"Aspis delivery delay\",\"body\":\"My shield order has not arrived before the battle at Thermopylae.\"}\n\n" .
            "Run with Ollama:\n" .
            "  OLLAMA_MODEL=qwen2.5-coder:7b php demos/athena/support-triage/server.php",
            $choice->description,
        ))
        ->run();
};
