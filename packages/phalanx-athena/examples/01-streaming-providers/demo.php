<?php

/**
 * Concurrent streaming across two LLM providers.
 *
 * Boots Aegis, opens a streaming completion against Anthropic and OpenAI
 * in parallel, and prints token deltas to stdout as they arrive. Exits 0
 * cleanly without API keys (boot + provider wiring is exercised, the live
 * HTTP path is skipped). With keys, both providers stream concurrently
 * and the demo reports per-provider token usage at the end.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Phalanx\Application;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Athena\Provider\AnthropicConfig;
use Phalanx\Athena\Provider\AnthropicProvider;
use Phalanx\Athena\Provider\GenerateRequest;
use Phalanx\Athena\Provider\OpenAiConfig;
use Phalanx\Athena\Provider\OpenAiProvider;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

$context = ['argv' => $argv ?? []];
$app = Application::starting($context)->compile();

$anthropicKey = (string) ($_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: '');
$openaiKey = (string) ($_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '');

if ($anthropicKey === '' || $openaiKey === '') {
    echo "Streaming-providers demo wired.\n";
    echo "Set ANTHROPIC_API_KEY and OPENAI_API_KEY to run the live HTTP path.\n";
    echo "  ANTHROPIC_API_KEY: " . ($anthropicKey === '' ? 'missing' : 'present') . "\n";
    echo "  OPENAI_API_KEY:    " . ($openaiKey === '' ? 'missing' : 'present') . "\n";
    exit(0);
}

$prompt = 'Write one short sentence about coroutines.';
$conversation = Conversation::create()->user($prompt);
$request = GenerateRequest::from($conversation)->withMaxTokens(150);

$anthropic = new AnthropicProvider(new AnthropicConfig(apiKey: $anthropicKey));
$openai = new OpenAiProvider(new OpenAiConfig(apiKey: $openaiKey));

$exitCode = $app->run(Task::named(
    'demo.athena.streaming-providers',
    static function (ExecutionScope $scope) use ($anthropic, $openai, $request): int {
        echo "Streaming concurrently from Anthropic + OpenAI...\n\n";

        $results = $scope->concurrent(
            anthropic: Task::of(static function (ExecutionScope $s) use ($anthropic, $request): array {
                $out = '';
                $usage = null;
                foreach ($anthropic->generate($request)($s) as $event) {
                    if ($event->kind === AgentEventKind::TokenDelta) {
                        $delta = (string) ($event->data->text ?? '');
                        $out .= $delta;
                        echo "[anthropic] {$delta}";
                    }
                    if ($event->kind === AgentEventKind::TokenComplete) {
                        $usage = $event->usageSoFar;
                    }
                }
                return ['text' => $out, 'usage' => $usage];
            }),
            openai: Task::of(static function (ExecutionScope $s) use ($openai, $request): array {
                $out = '';
                $usage = null;
                foreach ($openai->generate($request)($s) as $event) {
                    if ($event->kind === AgentEventKind::TokenDelta) {
                        $delta = (string) ($event->data->text ?? '');
                        $out .= $delta;
                        echo "[openai]    {$delta}";
                    }
                    if ($event->kind === AgentEventKind::TokenComplete) {
                        $usage = $event->usageSoFar;
                    }
                }
                return ['text' => $out, 'usage' => $usage];
            }),
        );

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
