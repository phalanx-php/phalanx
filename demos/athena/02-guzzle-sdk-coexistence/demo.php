<?php

/**
 * Coexistence between an Athena-native streaming call and a Guzzle-using SDK.
 *
 * Many third-party SDKs (AWS, Stripe, official OpenAI/Anthropic clients) are
 * built on Guzzle. Guzzle's default cURL handler blocks the OpenSwoole
 * reactor; CoroutineGuzzleStack swaps it for hyperf/guzzle's coroutine handler
 * so the SDK cooperates with Phalanx-managed concurrency.
 *
 * Usage:
 *   cp .env.example .env && php demo.php
 *   ATHENA_DEMO_LIVE=1 ANTHROPIC_API_KEY=sk-... GUZZLE_DEMO_URL=https://example.com php demo.php
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Athena\Athena;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Http\CoroutineGuzzleStack;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Athena\Provider\GenerateRequest;
use Phalanx\Athena\Provider\ProviderConfig;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Athena\Support\DemoContextKeys;
use Phalanx\Demos\Athena\Support\LiveModeFlag;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

return DemoReport::demo(
    'Athena Guzzle SDK Coexistence',
    static function (DemoReport $report, AppContext $context): void {
        $ctx = (new LiveModeFlag($context))->effective();

        $anthropicKey    = $ctx->get(DemoContextKeys::ANTHROPIC_API_KEY, '');
        $guzzleUrl       = $ctx->get(DemoContextKeys::GUZZLE_DEMO_URL, '');
        $guzzleAvailable = class_exists(\GuzzleHttp\Client::class)
            && class_exists(\Hyperf\Guzzle\CoroutineHandler::class);

        if (!$guzzleAvailable) {
            $report->cannotRun(
                'guzzlehttp/guzzle and hyperf/guzzle are not installed.',
                'run `composer install` from the monorepo root.',
            );
            return;
        }
        if (!is_string($anthropicKey) || $anthropicKey === '') {
            $report->cannotRun(
                'a live Anthropic API key was not provided.',
                'rerun with `ATHENA_DEMO_LIVE=1 ANTHROPIC_API_KEY=... php demo.php`.',
            );
            return;
        }
        if (!is_string($guzzleUrl) || $guzzleUrl === '') {
            $report->cannotRun(
                'a Guzzle target URL was not provided.',
                'rerun with `GUZZLE_DEMO_URL=https://example.com php demo.php` plus the live Anthropic variables.',
            );
            return;
        }

        $scheme = parse_url($guzzleUrl, PHP_URL_SCHEME);
        $host   = parse_url($guzzleUrl, PHP_URL_HOST);

        if (!in_array($scheme, ['http', 'https'], true)) {
            $report->cannotRun(
                'GUZZLE_DEMO_URL must start with http:// or https://.',
                'rerun with `GUZZLE_DEMO_URL=https://example.com php demo.php` plus the live Anthropic variables.',
            );
            return;
        }
        if ($scheme === 'https' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $report->cannotRun(
                'GUZZLE_DEMO_URL points HTTPS at a local address.',
                'use `http://localhost:8888` for a plain local server, or use a real HTTPS endpoint.',
            );
            return;
        }

        $request = GenerateRequest::from(
            Conversation::create()->user('In one short sentence, describe Athena as a strategist and patron of wisdom.'),
        )->withMaxTokens(20);

        $stack  = CoroutineGuzzleStack::create();
        $guzzle = new \GuzzleHttp\Client(['handler' => $stack, 'timeout' => 10.0]);

        Athena::starting($ctx->values)->run(Task::named(
            'demo.athena.guzzle-sdk-coexistence',
            static function (ExecutionScope $scope) use ($guzzle, $guzzleUrl, $request, $report): void {
                $report->note('Running Athena native + Guzzle SDK concurrently...');

                $settlements = $scope->settle(
                    athena: Task::of(static function (ExecutionScope $s) use ($request): string {
                        $providerConfig = $s->service(ProviderConfig::class);
                        $provider       = $providerConfig->resolve('anthropic');
                        $out = '';
                        foreach ($provider->generate($request)($s) as $event) {
                            if ($event->kind === AgentEventKind::TokenDelta) {
                                $out .= (string) ($event->data->text ?? '');
                            }
                        }

                        return $out;
                    }),
                    guzzle: Task::of(static fn (): int => $guzzle->get($guzzleUrl)->getStatusCode()),
                );

                $athenaResult = $settlements->settlement('athena');
                $guzzleResult = $settlements->settlement('guzzle');

                $athenaOk = $athenaResult?->isOk === true;
                $athenaDetail = $athenaOk
                    ? sprintf('reply: %s', trim((string) $athenaResult->value))
                    : sprintf('failed: %s', $athenaResult?->error()?->getMessage() ?? 'unknown error');
                $report->record(sprintf('athena native — %s', $athenaDetail), $athenaOk);

                $guzzleOk = $guzzleResult?->isOk === true;
                $guzzleDetail = $guzzleOk
                    ? sprintf('GET status: %s', $guzzleResult->value)
                    : sprintf('failed: %s', $guzzleResult?->error()?->getMessage() ?? 'unknown error');
                $report->record(sprintf('guzzle SDK — %s', $guzzleDetail), $guzzleOk);
            },
        ));
    },
);
