<?php

/**
 * Concurrent streaming across LLM providers.
 *
 * Boots Aegis, opens a streaming completion against every configured
 * provider in parallel, and prints token deltas to stdout as they
 * arrive. Local Ollama can be enabled through the runtime context.
 * Anthropic and OpenAI are additive: they fan in when their respective
 * keys are present in the runtime context.
 *
 * Without configured providers, the demo prints what it needs and exits 0.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Phalanx\Athena\Athena;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Athena\Provider\AnthropicConfig;
use Phalanx\Athena\Provider\AnthropicProvider;
use Phalanx\Athena\Provider\GenerateRequest;
use Phalanx\Athena\Provider\LlmProvider;
use Phalanx\Athena\Provider\OllamaConfig;
use Phalanx\Athena\Provider\OllamaProvider;
use Phalanx\Athena\Provider\OpenAiConfig;
use Phalanx\Athena\Provider\OpenAiProvider;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

/** @var array<string, mixed> $context */
$context = phalanxAthenaExampleContext($argv ?? []);

$anthropicKey = (string) ($context['ANTHROPIC_API_KEY'] ?? '');
$openaiKey = (string) ($context['OPENAI_API_KEY'] ?? '');

/** @var array<string, LlmProvider> $providers */
$providers = [];
if (
    ($context['OLLAMA_ENABLED'] ?? false) === true
    || array_key_exists('OLLAMA_MODEL', $context)
    || array_key_exists('OLLAMA_BASE_URL', $context)
) {
    $defaults = new OllamaConfig();
    $providers['ollama'] = new OllamaProvider(new OllamaConfig(
        model: (string) ($context['OLLAMA_MODEL'] ?? $defaults->model),
        baseUrl: (string) ($context['OLLAMA_BASE_URL'] ?? $defaults->baseUrl),
    ));
}
if ($anthropicKey !== '') {
    $providers['anthropic'] = new AnthropicProvider(new AnthropicConfig(apiKey: $anthropicKey));
}
if ($openaiKey !== '') {
    $providers['openai'] = new OpenAiProvider(new OpenAiConfig(apiKey: $openaiKey));
}

if ($providers === []) {
    echo <<<'TEXT'
Athena Streaming Providers
==========================
Status: skipped

Nothing is wrong with the demo wiring. No provider is enabled for this run.

Current configuration:

TEXT;
    printf("  %-18s %s\n", 'OLLAMA_ENABLED', phalanxAthenaExampleEnvStatus('OLLAMA_ENABLED'));
    printf("  %-18s %s\n", 'OLLAMA_MODEL', phalanxAthenaExampleEnvStatus('OLLAMA_MODEL'));
    printf("  %-18s %s\n", 'OLLAMA_BASE_URL', phalanxAthenaExampleEnvStatus('OLLAMA_BASE_URL'));
    printf("  %-18s %s\n", 'ANTHROPIC_API_KEY', phalanxAthenaExampleEnvStatus('ANTHROPIC_API_KEY', requiresLive: true));
    printf("  %-18s %s\n", 'OPENAI_API_KEY', phalanxAthenaExampleEnvStatus('OPENAI_API_KEY', requiresLive: true));
    $command = phalanxAthenaExampleComposerCommand('demo:athena:streaming', 'demo:streaming');
    $instructions = <<<'TEXT'

Run locally with Ollama:
  OLLAMA_ENABLED=true %s

Run with hosted providers:
  ATHENA_DEMO_LIVE=1 ANTHROPIC_API_KEY=... %s
  ATHENA_DEMO_LIVE=1 OPENAI_API_KEY=... %s

TEXT;

    printf(
        $instructions,
        $command,
        $command,
        $command,
    );
    exit(0);
}

echo "Streaming concurrently from: " . implode(', ', array_keys($providers)) . "\n\n";

$prompt = 'Write one short sentence about coroutines.';
$request = GenerateRequest::from(Conversation::create()->user($prompt))->withMaxTokens(150);

$tasks = [];
foreach ($providers as $name => $provider) {
    $label = str_pad($name, 10);
    $tasks[$name] = Task::of(static function (ExecutionScope $s) use ($provider, $request, $label): array {
        $out = '';
        $usage = null;
        try {
            foreach ($provider->generate($request)($s) as $event) {
                if ($event->kind === AgentEventKind::TokenDelta) {
                    $delta = (string) ($event->data->text ?? '');
                    $out .= $delta;
                    echo "[{$label}] {$delta}";
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

$exitCode = Athena::starting($context)->run(Task::named(
    'demo.athena.streaming-providers',
    static function (ExecutionScope $scope) use ($tasks): int {
        $results = $scope->concurrent(...$tasks);

        echo "\n\n--- summary ---\n";
        foreach ($results as $name => $r) {
            $u = $r['usage'];
            $tokens = $u !== null ? "{$u->input}/{$u->output}" : 'n/a';
            printf("%-10s tokens(in/out): %s\n", $name, $tokens);
            if ($r['error'] !== null) {
                printf("%-10s error: %s\n", $name, $r['error']);
            }
        }

        return 0;
    },
));

exit((int) $exitCode);
