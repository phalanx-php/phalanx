<?php

/**
 * Third-party SDK (Guzzle) alongside Athena native streaming.
 *
 * Many third-party SDKs (AWS, Stripe, official provider clients) are built on
 * Guzzle. This demo shows that a Guzzle-backed HTTP call can run alongside an
 * Athena-native streaming invocation inside the same Aegis execution scope
 * without interfering with task supervision, cancellation, or cleanup.
 *
 * Both legs settle through $scope->settle():
 *   - Athena leg: streams a short response via Ollama (or scripted Fake).
 *   - Guzzle leg: fetches a URL (defaults to https://httpbin.org/status/200).
 *
 * With Aegis runtime hooks enabled, the Guzzle leg is coroutine-aware; without
 * hooks, it blocks the worker. This demo does not assert which regime is active.
 *
 * Prerequisites:
 *   - openswoole extension loaded
 *   - guzzlehttp/guzzle installed (require-dev)
 *
 * Optional env:
 *   - OLLAMA_BASE_URL — Ollama endpoint (default http://localhost:11434)
 *   - OLLAMA_MODEL    — model name (default qwen2.5-coder:7b)
 *   - OLLAMA_ENABLED  — set to 0 to skip the Ollama probe and use Fake
 *   - GUZZLE_DEMO_URL — http(s) URL Guzzle should hit (default https://httpbin.org/status/200)
 *
 * Usage:
 *   php -d extension=openswoole demos/athena/02-third-party-sdk-alongside-athena/demo.php
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Athena\Activity\Config as ActivityConfig;
use Phalanx\Athena\Athena;
use Phalanx\Athena\AthenaBundle;
use Phalanx\Athena\Router\SingleProviderRouter;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoProvider;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Demos\Kit\FakeCueScript;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskScope;
use Phalanx\Task\Task;

// Aegis kernel requires OpenSwoole\Table; guard before boot.
if (!extension_loaded('openswoole')) {
    return DemoReport::demo(
        'Athena Third-Party SDK Alongside Athena',
        static function (DemoReport $report): void {
            $report->cannotRun(
                'openswoole extension required',
                'Run with: php -d extension=openswoole demos/athena/02-third-party-sdk-alongside-athena/demo.php',
            );
        },
    );
}

// The outer closure receives $context from symfony/runtime before DemoApp::boot
// creates the Aegis kernel. We read the provider env here so we can build the
// AthenaBundle (which needs a concrete provider/router) before boot.
return static function (array $context): Closure {
    $guzzleUrl = (string) ($context['GUZZLE_DEMO_URL'] ?? 'https://httpbin.org/status/200');
    $baseUrl   = (string) ($context['OLLAMA_BASE_URL'] ?? 'http://localhost:11434');
    $model     = (string) ($context['OLLAMA_MODEL']    ?? 'qwen2.5-coder:7b');
    $enabled   = ((string) ($context['OLLAMA_ENABLED'] ?? '1')) !== '0';

    // Preflight: Guzzle must be installed. URL must be parseable.
    if (!class_exists(\GuzzleHttp\Client::class)) {
        $inner = DemoReport::demo(
            'Athena Third-Party SDK Alongside Athena',
            static function (DemoReport $report): void {
                $report->cannotRun(
                    'guzzlehttp/guzzle is not installed',
                    'run `composer install` from the framework root',
                );
            },
        );
        // context not needed for preflight-only paths; body never executes DemoApp.
        return ($inner)([]);
    }

    $scheme = parse_url($guzzleUrl, PHP_URL_SCHEME);
    $host   = parse_url($guzzleUrl, PHP_URL_HOST);

    if (!in_array($scheme, ['http', 'https'], true)) {
        $inner = DemoReport::demo(
            'Athena Third-Party SDK Alongside Athena',
            static function (DemoReport $report): void {
                $report->cannotRun(
                    'GUZZLE_DEMO_URL must start with http:// or https://',
                    'rerun with GUZZLE_DEMO_URL=https://httpbin.org/status/200',
                );
            },
        );
        // context not needed for preflight-only paths; body never executes DemoApp.
        return ($inner)([]);
    }

    if ($scheme === 'https' && is_string($host) && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        $inner = DemoReport::demo(
            'Athena Third-Party SDK Alongside Athena',
            static function (DemoReport $report): void {
                $report->cannotRun(
                    'GUZZLE_DEMO_URL points HTTPS at a local address',
                    'use http://localhost:<port> for local servers or a real HTTPS endpoint',
                );
            },
        );
        // context not needed for preflight-only paths; body never executes DemoApp.
        return ($inner)([]);
    }

    // Pick the panoply provider. Try Ollama first; fall back to a scripted Fake.
    $fakeScript = FakeCueScript::tokens(
        'Athena is the disciplined patron of wisdom and strategic clarity.',
        activityId: 'demo-guzzle-coexistence',
        agentId: 'pericles-strategist',
    );
    $choice = $enabled
        ? DemoProvider::ollamaOrFake($fakeScript, model: $model, baseUrl: $baseUrl)
        : DemoProvider::fakeOnly($fakeScript);

    $bundle = new AthenaBundle(new SingleProviderRouter($choice->provider));

    // Pericles-themed agent — strategic counsel without tool use.
    $agent = new class () implements \Phalanx\Panoply\Agent {
        public string $id { get => 'pericles-strategist'; }
        public string $name { get => 'Pericles Strategist'; }
        public string $purpose {
            get => 'In one short sentence, describe Athena as a strategist and patron of wisdom.';
        }
        public Output $output { get => Output::text(); }
        public Context $context { get => Context::new(); }
        public Effects $effects { get => Effects::none(); }
        public ProviderNeeds $provider {
            get => ProviderNeeds::new()->prefer(Preference::LocalFirst);
        }
        public Capabilities $capabilities {
            get => Capabilities::of(Capability::Streaming);
        }
        public TransportNeeds $transport {
            get => TransportNeeds::new()->streaming();
        }
    };

    $capturedUrl = $guzzleUrl;
    $providerDesc = $choice->description;

    // DemoApp::boot returns the inner Closure directly; symfony/runtime
    // invokes our outer static fn and calls the returned Closure.
    $bootClosure = DemoApp::boot(
        'Athena Third-Party SDK Alongside Athena',
        static function (DemoApp $app, DemoReport $report) use ($agent, $capturedUrl, $providerDesc): void {
            $report->note(sprintf('provider: %s', $providerDesc));
            $report->note(sprintf('guzzle target: %s', $capturedUrl));
            $report->note('topic: Athena as strategist and patron of wisdom');

            $guzzle = new \GuzzleHttp\Client(['timeout' => 10.0]);

            $settled = $app->run(
                Task::named(
                    'demo.athena.guzzle-sdk-coexistence',
                    static function (ExecutionScope $scope) use ($agent, $guzzle, $capturedUrl): mixed {
                        $athenaText = '';

                        return $scope->settle(
                            athena: Task::of(static function (TaskScope $s) use ($agent, &$athenaText): string {
                                $config = new ActivityConfig(
                                    id: 'demo-guzzle-coexistence',
                                    context: Context::new(),
                                    maxInvocations: 1,
                                );
                                $result = Athena::run($s, $agent, $config);
                                foreach ($result->stream->toArray() as $cue) {
                                    if ($cue instanceof TokenDelta) {
                                        $athenaText .= $cue->text;
                                    }
                                }
                                return trim($athenaText);
                            }),
                            guzzle: Task::of(static fn (): int => $guzzle->get($capturedUrl)->getStatusCode()),
                        );
                    },
                ),
            );

            $athenaS = $settled->settlement('athena');
            $guzzleS = $settled->settlement('guzzle');

            $athenaOk = $athenaS?->isOk === true;
            $athenaDetail = $athenaOk
                ? sprintf('reply: %s', $athenaS->value)
                : sprintf('failed: %s', $athenaS?->error()?->getMessage() ?? 'unknown');
            $report->record(sprintf('athena native — %s', $athenaDetail), $athenaOk);

            $guzzleOk = $guzzleS?->isOk === true;
            $guzzleDetail = $guzzleOk
                ? sprintf('GET status: %d', $guzzleS->value)
                : sprintf('failed: %s', $guzzleS?->error()?->getMessage() ?? 'unknown');
            $report->record(sprintf('guzzle SDK — %s', $guzzleDetail), $guzzleOk);

            $report->record(
                'scope cleanup left no orphaned tasks',
                $app->ledger()->liveTaskCount() === 0,
            );
        },
        [$bundle],
    );

    // symfony/runtime calls: outer_fn($context) -> inner_fn(). The outer fn
    // is our static function above; it must return the inner Closure.
    // DemoApp::boot($title, $body, $bundles) returns static fn(array $context): Closure.
    // We've already consumed $context here, so call it with an empty array
    // (the inner closure doesn't use $context again; all env values are captured).
    return ($bootClosure)([]);
};
