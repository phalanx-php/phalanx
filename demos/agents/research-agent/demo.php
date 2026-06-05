<?php

/**
 * Research Agent — Agent v2 multi-tool activity.
 *
 * Runs a ResearchAgent that extracts document content, queries spreadsheet
 * data, and cross-references findings. Three tools are registered:
 *   - extract_document_content  (backed by ExtractDocumentContent)
 *   - query_spreadsheet         (backed by QuerySpreadsheet)
 *   - cross_reference           (backed by CrossReference)
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
 *   php demos/agents/research-agent/demo.php
 *   php -d extension=swoole demos/agents/research-agent/demo.php
 *   OLLAMA_MODEL=mistral php -d extension=swoole demos/agents/research-agent/demo.php
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Acme\ResearchAgent;
use Acme\Tools\CrossReference;
use Acme\Tools\ExtractDocumentContent;
use Acme\Tools\QuerySpreadsheet;
use Phalanx\Agents\Activity\Config as ActivityConfig;
use Phalanx\Agents\Activity\State;
use Phalanx\Agents\Agents;
use Phalanx\Agents\AgentsBundle;
use Phalanx\Agents\Router\SingleProviderRouter;
use Phalanx\Agents\Tool\ToolBundle;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoProvider;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Demos\Kit\FakeCueScript;
use Phalanx\AiProviders\Context;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\AiProviders\Id;
use Phalanx\Scope\TaskScope;

return static function (array $context): Closure {
    if (!extension_loaded('swoole')) {
        $inner = DemoReport::demo(
            'Agent Research Agent',
            static function (DemoReport $report): void {
                $report->cannotRun(
                    'swoole extension required',
                    'Run with: php -d extension=swoole demos/agents/research-agent/demo.php',
                );
            },
        );
        return ($inner)([]);
    }

    $fakeScript = FakeCueScript::tokens(
        'Analysis complete. The phalanx formation dominates 73% of recorded Spartan engagements. ' .
        'Cross-referencing three documents confirms disciplined coordination as the decisive factor.',
        activityId: 'research-demo',
        agentId: 'research-agent',
    );

    $baseUrl = (string) ($context['OLLAMA_BASE_URL'] ?? DemoProvider::OLLAMA_BASE);
    $model   = (string) ($context['OLLAMA_MODEL']    ?? 'qwen2.5-coder:7b');
    $enabled = ($context['OLLAMA_ENABLED'] ?? '1') !== '0';

    $choice = $enabled
        ? DemoProvider::ollamaOrFake($fakeScript, $model, $baseUrl)
        : DemoProvider::fakeOnly($fakeScript);

    $toolBundle = (new ToolBundle())
        ->add('extract_document_content', ExtractDocumentContent::class)
        ->add('query_spreadsheet', QuerySpreadsheet::class)
        ->add('cross_reference', CrossReference::class);

    $bundle = new AgentsBundle(
        router: new SingleProviderRouter($choice->provider),
        toolBundles: [$toolBundle],
    );

    $bootClosure = DemoApp::boot(
        'Agent Research Agent',
        static function (DemoApp $app, DemoReport $report) use ($choice): void {
            $report->note(sprintf('provider: %s', $choice->description));
            $report->note('topic: Spartan military history — phalanx formation analysis');
            $report->note('tools: extract_document_content, query_spreadsheet, cross_reference');

            $agent  = new ResearchAgent();
            $config = new ActivityConfig(
                id: Id::generate(),
                context: Context::new(),
                maxInvocations: 6,
                timeoutSeconds: 60.0,
            );

            $tokenCount = 0;
            $fullText   = '';

            $result = $app->run(
                static function (TaskScope $scope) use ($agent, $config, &$tokenCount, &$fullText): \Phalanx\Agents\Activity\Result {
                    $result = Agents::run($scope, $agent, $config);

                    foreach ($result->stream->toArray() as $cue) {
                        if ($cue instanceof TokenDelta) {
                            $fullText .= $cue->text;
                            $tokenCount++;
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
