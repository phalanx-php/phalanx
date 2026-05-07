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

$scheme = parse_url($guzzleUrl, PHP_URL_SCHEME);
$host = parse_url($guzzleUrl, PHP_URL_HOST);

if (!in_array($scheme, ['http', 'https'], true)) {
    phalanxAthenaExampleCannotRun(
        'Athena Guzzle SDK Coexistence',
        'GUZZLE_DEMO_URL must start with http:// or https://.',
        'rerun with `GUZZLE_DEMO_URL=https://example.com ' . $command . '` plus the live Anthropic variables.',
    );
}

if ($scheme === 'https' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    phalanxAthenaExampleCannotRun(
        'Athena Guzzle SDK Coexistence',
        'GUZZLE_DEMO_URL points HTTPS at a local address.',
        'use `http://localhost:8888` for a plain local server, or use a real HTTPS endpoint.',
    );
}

$prompt = 'In one short sentence, describe Athena as a strategist and patron of wisdom.';
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

        $settlements = $scope->settle(
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

        $athena = $settlements->settlement('athena');
        $guzzle = $settlements->settlement('guzzle');
        $failed = false;

        echo "Results:\n";

        if ($athena?->isOk === true) {
            printf("  Athena reply: %s\n", trim((string) $athena->value));
        } else {
            $failed = true;
            printf("  Athena failed: %s\n", phalanxAthenaExampleThrowableMessage($athena?->error()));
        }

        if ($guzzle?->isOk === true) {
            printf("  Guzzle GET status: %s\n", $guzzle->value);
        } else {
            $failed = true;
            printf("  Guzzle failed: %s\n", phalanxAthenaExampleThrowableMessage($guzzle?->error()));
            echo "  Fix: verify GUZZLE_DEMO_URL is reachable from this machine.\n";
            echo "  Hint: if a local endpoint is plain HTTP, use http:// instead of https://.\n";
        }

        return $failed ? 1 : 0;
    },
));

exit((int) $exitCode);
