#!/usr/bin/env php
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
 * Usage:
 *   cp .env.example .env && php demo.php
 *   ATHENA_DEMO_LIVE=1 ANTHROPIC_API_KEY=sk-... GUZZLE_DEMO_URL=https://example.com php demo.php
 */

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload_runtime.php';

use Phalanx\Athena\Athena;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Examples\Support\DemoContextKeys;
use Phalanx\Athena\Examples\Support\DemoFailureRenderer;
use Phalanx\Athena\Examples\Support\LiveModeFlag;
use Phalanx\Athena\Http\CoroutineGuzzleStack;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Athena\Provider\GenerateRequest;
use Phalanx\Athena\Provider\ProviderConfig;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

return static function (array $context): \Closure {
    $ctx = AppContext::fromSymfonyRuntime($context);

    // Strip live-only keys when ATHENA_DEMO_LIVE is not set.
    $ctx = (new LiveModeFlag($ctx))->effective();

    $renderer        = new DemoFailureRenderer();
    $anthropicKey    = $ctx->get(DemoContextKeys::ANTHROPIC_API_KEY, '');
    $guzzleUrl       = $ctx->get(DemoContextKeys::GUZZLE_DEMO_URL, '');
    $guzzleAvailable = class_exists(\GuzzleHttp\Client::class)
        && class_exists(\Hyperf\Guzzle\CoroutineHandler::class);

    if (!$guzzleAvailable) {
        return static fn (): int => $renderer->cannotRun(
            'Athena Guzzle SDK Coexistence',
            'guzzlehttp/guzzle and hyperf/guzzle are not installed.',
            'run `composer install` from the monorepo root.',
        );
    }

    if (!is_string($anthropicKey) || $anthropicKey === '') {
        return static fn (): int => $renderer->cannotRun(
            'Athena Guzzle SDK Coexistence',
            'a live Anthropic API key was not provided.',
            'rerun with `ATHENA_DEMO_LIVE=1 ANTHROPIC_API_KEY=... php demo.php`.',
        );
    }

    if (!is_string($guzzleUrl) || $guzzleUrl === '') {
        return static fn (): int => $renderer->cannotRun(
            'Athena Guzzle SDK Coexistence',
            'a Guzzle target URL was not provided.',
            'rerun with `GUZZLE_DEMO_URL=https://example.com php demo.php` plus the live Anthropic variables.',
        );
    }

    $scheme = parse_url($guzzleUrl, PHP_URL_SCHEME);
    $host   = parse_url($guzzleUrl, PHP_URL_HOST);

    if (!in_array($scheme, ['http', 'https'], true)) {
        return static fn (): int => $renderer->cannotRun(
            'Athena Guzzle SDK Coexistence',
            'GUZZLE_DEMO_URL must start with http:// or https://.',
            'rerun with `GUZZLE_DEMO_URL=https://example.com php demo.php` plus the live Anthropic variables.',
        );
    }

    if ($scheme === 'https' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return static fn (): int => $renderer->cannotRun(
            'Athena Guzzle SDK Coexistence',
            'GUZZLE_DEMO_URL points HTTPS at a local address.',
            'use `http://localhost:8888` for a plain local server, or use a real HTTPS endpoint.',
        );
    }

    $prompt       = 'In one short sentence, describe Athena as a strategist and patron of wisdom.';
    $conversation = Conversation::create()->user($prompt);
    $request      = GenerateRequest::from($conversation)->withMaxTokens(20);

    $stack  = CoroutineGuzzleStack::create();
    $guzzle = new \GuzzleHttp\Client(['handler' => $stack, 'timeout' => 10.0]);

    return static fn (): int => (int) Athena::starting($ctx)->run(Task::named(
        'demo.athena.guzzle-sdk-coexistence',
        static function (ExecutionScope $scope) use ($guzzle, $guzzleUrl, $request): int {
            echo "Running Athena native + Guzzle SDK concurrently...\n\n";

            $settlements = $scope->settle(
                athena: Task::of(static function (ExecutionScope $s) use ($request): string {
                    $providerConfig = $s->service(ProviderConfig::class);
                    $provider       = $providerConfig->resolve('anthropic');
                    $out            = '';
                    foreach ($provider->generate($request)($s) as $event) {
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

            $athenaResult = $settlements->settlement('athena');
            $guzzleResult = $settlements->settlement('guzzle');
            $failed       = false;

            echo "Results:\n";

            if ($athenaResult?->isOk === true) {
                printf("  Athena reply: %s\n", trim((string) $athenaResult->value));
            } else {
                $failed = true;
                printf("  Athena failed: %s\n", DemoFailureRenderer::messageOf($athenaResult?->error()));
            }

            if ($guzzleResult?->isOk === true) {
                printf("  Guzzle GET status: %s\n", $guzzleResult->value);
            } else {
                $failed = true;
                printf("  Guzzle failed: %s\n", DemoFailureRenderer::messageOf($guzzleResult?->error()));
                echo "  Fix: verify GUZZLE_DEMO_URL is reachable from this machine.\n";
                echo "  Hint: if a local endpoint is plain HTTP, use http:// instead of https://.\n";
            }

            return $failed ? 1 : 0;
        },
    ));
};
