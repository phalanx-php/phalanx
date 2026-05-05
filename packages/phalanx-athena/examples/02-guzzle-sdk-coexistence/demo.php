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
 * Without ANTHROPIC_API_KEY, GUZZLE_DEMO_URL, or the Guzzle bridge
 * dependencies, the demo exits with one actionable fix.
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
$context = phalanxAthenaExampleContext($argv ?? []);

$anthropicKey = (string) ($context['ANTHROPIC_API_KEY'] ?? '');
$guzzleUrl = (string) ($context['GUZZLE_DEMO_URL'] ?? '');
$guzzleAvailable = class_exists(\GuzzleHttp\Client::class) && class_exists(\Hyperf\Guzzle\CoroutineHandler::class);
$command = phalanxAthenaExampleComposerCommand('demo:athena:guzzle', 'demo:guzzle');

if (!$guzzleAvailable) {
    phalanxAthenaExampleCannotRun(
        'Athena Guzzle SDK Coexistence',
        'guzzlehttp/guzzle and hyperf/guzzle are not installed.',
        'run `composer install` from the monorepo root.',
    );
}

if ($anthropicKey === '') {
    phalanxAthenaExampleCannotRun(
        'Athena Guzzle SDK Coexistence',
        'a live Anthropic API key was not provided.',
        'rerun with `ATHENA_DEMO_LIVE=1 ANTHROPIC_API_KEY=... ' . $command . '`.',
    );
}

if ($guzzleUrl === '') {
    phalanxAthenaExampleCannotRun(
        'Athena Guzzle SDK Coexistence',
        'a Guzzle target URL was not provided.',
        'rerun with `GUZZLE_DEMO_URL=https://example.com ' . $command . '` plus the live Anthropic variables.',
    );
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
