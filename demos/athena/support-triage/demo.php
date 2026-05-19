<?php

/**
 * Support Triage Agent — Athena v2 multi-tool activity.
 *
 * Runs one triage cycle with a scripted ticket payload, streams cues
 * to the DemoReport, and exits 0. Four tools are registered:
 *   - lookup_customer
 *   - search_knowledge_base
 *   - get_recent_tickets
 *   - check_service_status
 *
 * Probes Ollama at localhost:11434 and falls back to a deterministic Fake
 * provider when Ollama is unreachable or the requested model is not installed.
 *
 * Environment variables (read from $context via symfony/runtime):
 *   OLLAMA_BASE_URL  — Ollama base URL (default: http://localhost:11434)
 *   OLLAMA_MODEL     — model name (default: qwen2.5-coder:7b)
 *   OLLAMA_ENABLED   — set to "0" to skip Ollama probe and use Fake directly
 *
 * Usage:
 *   php demos/athena/support-triage/demo.php
 *   php -d extension=openswoole demos/athena/support-triage/demo.php
 *   OLLAMA_MODEL=mistral php -d extension=openswoole demos/athena/support-triage/demo.php
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Acme\SupportTriageAgent;
use Acme\Tools\CheckServiceStatus;
use Acme\Tools\GetRecentTickets;
use Acme\Tools\LookupCustomer;
use Acme\Tools\SearchKnowledgeBase;
use Phalanx\Athena\Activity\Config as ActivityConfig;
use Phalanx\Athena\Activity\State;
use Phalanx\Athena\Athena;
use Phalanx\Athena\AthenaBundle;
use Phalanx\Athena\Router\SingleProviderRouter;
use Phalanx\Athena\Tool\ToolBundle;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoProvider;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Demos\Kit\FakeCueScript;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Id;
use Phalanx\Scope\TaskScope;

return static function (array $context): Closure {
    if (!extension_loaded('openswoole')) {
        $inner = DemoReport::demo(
            'Athena Support Triage Agent',
            static function (DemoReport $report): void {
                $report->cannotRun(
                    'openswoole extension required',
                    'Run with: php -d extension=openswoole demos/athena/support-triage/demo.php',
                );
            },
        );

        return ($inner)([]);
    }

    $fakeScript = FakeCueScript::tokens(
        'Priority: medium. Category: feature-request. ' .
        'Knowledge base articles found. Issue can be resolved from existing documentation.',
        activityId: 'support-triage-demo',
        agentId: 'support-triage-agent',
    );

    $baseUrl = (string) ($context['OLLAMA_BASE_URL'] ?? DemoProvider::OLLAMA_BASE);
    $model   = (string) ($context['OLLAMA_MODEL']    ?? 'qwen2.5-coder:7b');
    $enabled = ($context['OLLAMA_ENABLED'] ?? '1') !== '0';

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

    $bootClosure = DemoApp::boot(
        'Athena Support Triage Agent',
        static function (DemoApp $app, DemoReport $report) use ($choice): void {
            $report->note(sprintf('provider: %s', $choice->description));
            $report->note('ticket: Aspis delivery delay from hoplite@sparta.polis');
            $report->note('tools: lookup_customer, search_knowledge_base, get_recent_tickets, check_service_status');

            $agent  = new SupportTriageAgent();
            $config = new ActivityConfig(
                id: Id::generate(),
                context: Context::new(),
                maxInvocations: 4,
                timeoutSeconds: 25.0,
            );

            $tokenCount    = 0;
            $requestedCount = 0;
            $fullText      = '';

            $result = $app->run(
                static function (TaskScope $scope) use ($agent, $config, &$tokenCount, &$requestedCount, &$fullText): \Phalanx\Athena\Activity\Result {
                    $result = Athena::run($scope, $agent, $config);

                    foreach ($result->stream->toArray() as $cue) {
                        if ($cue instanceof TokenDelta) {
                            $fullText .= $cue->text;
                            $tokenCount++;
                        } elseif ($cue instanceof Requested) {
                            $requestedCount++;
                        }
                    }

                    return $result;
                },
            );

            if ($fullText !== '') {
                $report->note(sprintf('output: %s', trim($fullText)));
            }

            $report->record(
                'activity completed',
                $result->state === State::Completed,
            );

            $report->record(
                'at least one TokenDelta emitted',
                $tokenCount >= 1,
            );

            $report->record(
                'scope cleanup left no orphaned tasks',
                $app->ledger()->liveTaskCount() === 0,
            );
        },
        [$bundle],
    );

    return ($bootClosure)([]);
};
