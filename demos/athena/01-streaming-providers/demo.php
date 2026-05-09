<?php

/**
 * Concurrent streaming across LLM providers.
 *
 * Boots Aegis, opens a streaming completion against every configured
 * provider in parallel, and prints each completed provider response.
 * Local Ollama can be enabled through the runtime context. Anthropic
 * and OpenAI are additive: they fan in when their respective keys are
 * present in the runtime context.
 *
 * Without a configured provider, the demo exits with one actionable fix.
 *
 * Usage:
 *   cp .env.example .env && php demo.php
 *   ATHENA_DEMO_LIVE=1 ANTHROPIC_API_KEY=sk-... php demo.php
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Athena\Athena;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Athena\Provider\GenerateRequest;
use Phalanx\Athena\Provider\ProviderConfig;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Athena\Support\DemoContextKeys;
use Phalanx\Demos\Athena\Support\LiveModeFlag;
use Phalanx\Demos\Athena\Support\OllamaAutoDetect;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

return DemoReport::demo(
    'Athena Streaming Providers',
    static function (DemoReport $report, AppContext $context): void {
        $ctx = (new LiveModeFlag($context))->effective();
        $ctx = (new OllamaAutoDetect())($ctx);

        $anthropicKey = $ctx->get(DemoContextKeys::ANTHROPIC_API_KEY, '');
        $openaiKey    = $ctx->get(DemoContextKeys::OPENAI_API_KEY, '');

        $providers = [];
        if (
            $ctx->bool(DemoContextKeys::OLLAMA_ENABLED, false) === true
            || $ctx->has(DemoContextKeys::OLLAMA_MODEL)
            || $ctx->has(DemoContextKeys::OLLAMA_BASE_URL)
        ) {
            $providers[] = 'ollama';
        }
        if (is_string($anthropicKey) && $anthropicKey !== '') {
            $providers[] = 'anthropic';
        }
        if (is_string($openaiKey) && $openaiKey !== '') {
            $providers[] = 'openai';
        }

        if ($providers === []) {
            $report->cannotRun(
                'no runnable local Ollama model or live provider credentials were found.',
                'start Ollama, run `ollama pull llama3:8b`, then rerun this command.',
            );
            return;
        }

        $report->note('Providers: ' . implode(', ', $providers));
        $report->note("Topic: Athena's disciplined wisdom and strategic clarity");

        $request = GenerateRequest::from(
            Conversation::create()->user('In 18 words or fewer, connect Athena\'s disciplined wisdom and strategic clarity to an AI agent runtime.'),
        )->withMaxTokens(60);

        Athena::starting($ctx->values)->run(Task::named(
            'demo.athena.streaming-providers',
            static function (ExecutionScope $scope) use ($request, $report): void {
                $providerConfig = $scope->service(ProviderConfig::class);
                $tasks = [];

                foreach ($providerConfig->all() as $name => $provider) {
                    $tasks[$name] = Task::of(static function (ExecutionScope $s) use ($provider, $request): array {
                        $out = '';
                        $usage = null;

                        try {
                            foreach ($provider->generate($request)($s) as $event) {
                                if ($event->kind === AgentEventKind::TokenDelta) {
                                    $out .= (string) ($event->data->text ?? '');
                                }
                                if ($event->kind === AgentEventKind::TokenComplete) {
                                    $usage = $event->usageSoFar;
                                }
                            }
                        } catch (\Throwable $e) {
                            return ['text' => $out, 'usage' => $usage, 'error' => $e->getMessage()];
                        }

                        return ['text' => $out, 'usage' => $usage, 'error' => null];
                    });
                }

                $results = $scope->concurrent(...$tasks);

                foreach ($results as $name => $r) {
                    if ($r['text'] !== '') {
                        $report->note(sprintf('[%s] %s', $name, trim((string) $r['text'])));
                    }
                }

                foreach ($results as $name => $r) {
                    $usage = $r['usage'];
                    $tokens = $usage !== null ? "{$usage->input}/{$usage->output}" : 'n/a';
                    $detail = $r['error'] ?? sprintf('tokens in/out: %s', $tokens);
                    $report->record(sprintf('%-10s — %s', $name, $detail), $r['error'] === null);
                }
            },
        ));
    },
);
