<?php

/**
 * Concurrent streaming across LLM providers.
 *
 * Boots Aegis, opens a streaming completion against every reachable
 * provider in parallel, and prints token deltas to stdout as they
 * arrive. Local Ollama is the always-available proof — when it's
 * running on http://localhost:11434, the demo streams a real
 * end-to-end answer without any API keys. Anthropic and OpenAI are
 * additive: they fan in when their respective keys are set.
 *
 * Without Ollama and without keys, the demo prints what it would have
 * run and exits 0.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Phalanx\Application;
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

$context = ['argv' => $argv ?? []];
$app = Application::starting($context)->compile();

$ollamaUrl = (string) ($_ENV['OLLAMA_HOST'] ?? getenv('OLLAMA_HOST') ?: 'http://localhost:11434');
$ollamaModel = (string) ($_ENV['OLLAMA_MODEL'] ?? getenv('OLLAMA_MODEL') ?: 'llama3:8b');
$anthropicKey = (string) ($_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: '');
$openaiKey = (string) ($_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '');

$ollamaUp = @fsockopen(parse_url($ollamaUrl, PHP_URL_HOST) ?: 'localhost', parse_url($ollamaUrl, PHP_URL_PORT) ?: 11434, $_, $_, 0.5);
if ($ollamaUp) {
    fclose($ollamaUp);
    $ollamaUp = true;
} else {
    $ollamaUp = false;
}

/** @var array<string, LlmProvider> $providers */
$providers = [];
if ($ollamaUp) {
    $providers['ollama'] = new OllamaProvider(new OllamaConfig(model: $ollamaModel, baseUrl: $ollamaUrl));
}
if ($anthropicKey !== '') {
    $providers['anthropic'] = new AnthropicProvider(new AnthropicConfig(apiKey: $anthropicKey));
}
if ($openaiKey !== '') {
    $providers['openai'] = new OpenAiProvider(new OpenAiConfig(apiKey: $openaiKey));
}

if ($providers === []) {
    echo "Streaming-providers demo wired.\n";
    echo "No reachable providers. Run any of:\n";
    echo "  - Local Ollama at {$ollamaUrl} (just `ollama serve` and pull a model)\n";
    echo "  - Set ANTHROPIC_API_KEY for Anthropic streaming\n";
    echo "  - Set OPENAI_API_KEY for OpenAI streaming\n";
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
        return ['text' => $out, 'usage' => $usage];
    });
}

$exitCode = $app->run(Task::named(
    'demo.athena.streaming-providers',
    static function (ExecutionScope $scope) use ($tasks): int {
        $results = $scope->concurrent(...$tasks);

        echo "\n\n--- summary ---\n";
        foreach ($results as $name => $r) {
            $u = $r['usage'];
            $tokens = $u !== null ? "{$u->input}/{$u->output}" : 'n/a';
            printf("%-10s tokens(in/out): %s\n", $name, $tokens);
        }

        return 0;
    },
));

exit((int) $exitCode);
