<?php

/**
 * Coexistence between an Athena-native streaming call and a Guzzle-using SDK.
 *
 * Many third-party SDKs (AWS, Stripe, official OpenAI/Anthropic clients) are
 * built on Guzzle. Guzzle's default cURL handler blocks the OpenSwoole
 * reactor; CoroutineGuzzleStack swaps it for hyperf/guzzle's coroutine handler
 * so the SDK cooperates with Phalanx-managed concurrency.
 *
 * The demo runs an Athena native completion alongside a small Guzzle GET
 * via concurrent() — both should make progress without blocking each other.
 *
 * Without ANTHROPIC_API_KEY, or without `guzzlehttp/guzzle` + `hyperf/guzzle`
 * installed, the demo prints what would run and exits 0.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Phalanx\Application;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Http\CoroutineGuzzleStack;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Athena\Provider\AnthropicConfig;
use Phalanx\Athena\Provider\AnthropicProvider;
use Phalanx\Athena\Provider\GenerateRequest;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

$context = ['argv' => $argv ?? []];
$app = Application::starting($context)->compile();

$anthropicKey = (string) ($_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: '');
$guzzleAvailable = class_exists(\GuzzleHttp\Client::class) && class_exists(\Hyperf\Guzzle\CoroutineHandler::class);

if ($anthropicKey === '' || !$guzzleAvailable) {
    echo "Guzzle SDK coexistence demo wired.\n";
    echo "Requirements:\n";
    echo "  ANTHROPIC_API_KEY:                  " . ($anthropicKey === '' ? 'missing' : 'present') . "\n";
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

$exitCode = $app->run(Task::named(
    'demo.athena.guzzle-sdk-coexistence',
    static function (ExecutionScope $scope) use ($anthropic, $guzzle, $request): int {
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
            guzzle: Task::of(static function () use ($guzzle): int {
                $response = $guzzle->get('https://httpbin.org/anything');
                return $response->getStatusCode();
            }),
        );

        echo "athena reply: {$results['athena']}\n";
        echo "guzzle GET status: {$results['guzzle']}\n";

        return 0;
    },
));

exit((int) $exitCode);
