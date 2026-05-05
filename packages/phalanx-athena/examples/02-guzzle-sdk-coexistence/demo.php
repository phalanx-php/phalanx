<?php

/**
 * Coexistence between an Athena-native streaming call and a Guzzle-using SDK.
 *
 * Many third-party SDKs (AWS, Stripe, official OpenAI/Anthropic clients) are
 * built on Guzzle. Guzzle's default cURL handler blocks the OpenSwoole
 * reactor; CoroutineGuzzleStack swaps it for hyperf/guzzle's coroutine handler
 * so the SDK cooperates with Phalanx-managed concurrency.
 *
 * The demo runs an Athena native completion alongside a configured Guzzle
 * GET via concurrent() — both should make progress without blocking each
 * other.
 *
 * Without ANTHROPIC_API_KEY, GUZZLE_DEMO_URL, or `guzzlehttp/guzzle` +
 * `hyperf/guzzle`, the demo prints what would run and exits 0.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Phalanx\Athena\Athena;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Http\CoroutineGuzzleStack;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Athena\Provider\AnthropicConfig;
use Phalanx\Athena\Provider\AnthropicProvider;
use Phalanx\Athena\Provider\GenerateRequest;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

/** @var array<string, mixed> $context */
$context = ['argv' => $argv ?? []];

$anthropicKey = (string) ($context['ANTHROPIC_API_KEY'] ?? '');
$guzzleUrl = (string) ($context['GUZZLE_DEMO_URL'] ?? '');
$guzzleAvailable = class_exists(\GuzzleHttp\Client::class) && class_exists(\Hyperf\Guzzle\CoroutineHandler::class);

if ($anthropicKey === '' || $guzzleUrl === '' || !$guzzleAvailable) {
    echo "Guzzle SDK coexistence demo wired.\n";
    echo "Requirements:\n";
    echo "  ANTHROPIC_API_KEY:                  " . ($anthropicKey === '' ? 'missing' : 'present') . "\n";
    echo "  GUZZLE_DEMO_URL:                    " . ($guzzleUrl === '' ? 'missing' : 'present') . "\n";
    echo "  guzzlehttp/guzzle + hyperf/guzzle:  " . ($guzzleAvailable ? 'present' : 'install both to run live') . "\n";
    exit(0);
}

$prompt = 'Reply with the single word "done".';
$conversation = Conversation::create()->user($prompt);
$request = GenerateRequest::from($conversation)->withMaxTokens(20);
$anthropic = new AnthropicProvider(new AnthropicConfig(apiKey: $anthropicKey));

$stack = CoroutineGuzzleStack::create();
/** @var \GuzzleHttp\Client $guzzle */
$guzzle = new \GuzzleHttp\Client(['handler' => $stack, 'timeout' => 10.0]);

$exitCode = Athena::starting($context)->run(Task::named(
    'demo.athena.guzzle-sdk-coexistence',
    static function (ExecutionScope $scope) use ($anthropic, $guzzle, $guzzleUrl, $request): int {
        echo "Running Athena native + Guzzle SDK concurrently...\n\n";

        $results = $scope->concurrent(
            athena: Task::of(static function (ExecutionScope $s) use ($anthropic, $request): string {
                $out = '';
                foreach ($anthropic->generate($request)($s) as $event) {
                    if ($event->kind === AgentEventKind::TokenDelta) {
                        $out .= (string) ($event->data->text ?? '');
                    }
                }
                return $out;
            }),
            guzzle: Task::of(static function () use ($guzzle, $guzzleUrl): int {
                $response = $guzzle->get($guzzleUrl);
                return $response->getStatusCode();
            }),
        );

        echo "athena reply: {$results['athena']}\n";
        echo "guzzle GET status: {$results['guzzle']}\n";

        return 0;
    },
));

exit((int) $exitCode);
