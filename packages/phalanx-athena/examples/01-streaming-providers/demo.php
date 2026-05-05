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
    phalanxAthenaExampleCannotRun(
        'Athena Streaming Providers',
        'no runnable local Ollama model or live provider credentials were found.',
        'start Ollama, run `ollama pull llama3:8b`, then rerun this command.',
    );
}

$prompt = 'In 18 words or fewer, connect Athena\'s disciplined wisdom and strategic clarity to an AI agent runtime.';
$request = GenerateRequest::from(Conversation::create()->user($prompt))->withMaxTokens(60);

echo "Athena Streaming Providers\n";
echo "==========================\n";
echo "Providers: " . implode(', ', array_keys($providers)) . "\n";
echo "Topic: Athena's disciplined wisdom and strategic clarity\n\n";
echo "Responses:\n\n";

$tasks = [];
foreach ($providers as $name => $provider) {
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

$exitCode = Athena::starting($context)->run(Task::named(
    'demo.athena.streaming-providers',
    static function (ExecutionScope $scope) use ($tasks): int {
        $results = $scope->concurrent(...$tasks);
        $failed = false;

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
            $u = $r['usage'];
            $tokens = $u !== null ? "{$u->input}/{$u->output}" : 'n/a';
            $status = $r['error'] === null ? 'ok' : 'failed';
            printf("  %-10s %s, tokens in/out: %s\n", $name, $status, $tokens);
        }

        echo "\n";

        return $failed ? 1 : 0;
    },
));

exit((int) $exitCode);
