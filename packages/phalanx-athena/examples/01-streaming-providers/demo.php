#!/usr/bin/env php
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

require __DIR__ . '/../../../../vendor/autoload_runtime.php';

use Phalanx\Athena\Athena;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Examples\Support\DemoContextKeys;
use Phalanx\Athena\Examples\Support\DemoFailureRenderer;
use Phalanx\Athena\Examples\Support\LiveModeFlag;
use Phalanx\Athena\Examples\Support\OllamaAutoDetect;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Athena\Provider\GenerateRequest;
use Phalanx\Athena\Provider\ProviderConfig;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

return static function (array $context): int {
    $ctx = AppContext::fromSymfonyRuntime($context);

    // Strip live-only keys when ATHENA_DEMO_LIVE is not set, then
    // auto-detect a local Ollama instance and merge it into context.
    $ctx = (new LiveModeFlag($ctx))->effective();
    $ctx = (new OllamaAutoDetect())($ctx);

    $renderer     = new DemoFailureRenderer();
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
        return $renderer->cannotRun(
            'Athena Streaming Providers',
            'no runnable local Ollama model or live provider credentials were found.',
            'start Ollama, run `ollama pull llama3:8b`, then rerun this command.',
        );
    }

    $prompt  = 'In 18 words or fewer, connect Athena\'s disciplined wisdom and strategic clarity to an AI agent runtime.';
    $request = GenerateRequest::from(Conversation::create()->user($prompt))->withMaxTokens(60);

    echo "Athena Streaming Providers\n";
    echo "==========================\n";
    echo 'Providers: ' . implode(', ', $providers) . "\n";
    echo "Topic: Athena's disciplined wisdom and strategic clarity\n\n";
    echo "Responses:\n\n";

    return (int) Athena::starting($ctx)->run(Task::named(
        'demo.athena.streaming-providers',
        static function (ExecutionScope $scope) use ($request): int {
            $providerConfig = $scope->service(ProviderConfig::class);
            $tasks          = [];

            foreach ($providerConfig->all() as $name => $provider) {
                $tasks[$name] = Task::of(static function (ExecutionScope $s) use ($provider, $request): array {
                    $out   = '';
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
            $failed  = false;

            foreach ($results as $name => $r) {
                echo "[{$name}]\n";

                if ($r['text'] !== '') {
                    echo trim((string) $r['text']) . "\n\n";
                }

                if ($r['error'] !== null) {
                    $failed = true;
                    printf("Failed: %s\n\n", $r['error']);
                }
            }

            echo "Summary:\n";
            foreach ($results as $name => $r) {
                $u      = $r['usage'];
                $tokens = $u !== null ? "{$u->input}/{$u->output}" : 'n/a';
                $status = $r['error'] === null ? 'ok' : 'failed';
                printf("  %-10s %s, tokens in/out: %s\n", $name, $status, $tokens);
            }

            echo "\n";

            return $failed ? 1 : 0;
        },
    ));
};
