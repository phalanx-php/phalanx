<?php

/**
 * Guzzle SDK coexistence with Athena native streaming.
 *
 * Many third-party SDKs (AWS, Stripe, official provider clients) are built on
 * Guzzle. This demo shows that a Guzzle-backed HTTP call can coexist with an
 * Athena-native streaming invocation inside the same Aegis execution scope
 * without interfering with task supervision, cancellation, or cleanup.
 *
 * Both legs run concurrently inside $scope->settle():
 *   - Athena leg: streams a short Anthropic response via the panoply provider.
 *   - Guzzle leg: fetches a URL supplied through GUZZLE_DEMO_URL.
 *
 * Prerequisites:
 *   - openswoole extension loaded
 *   - guzzlehttp/guzzle installed (require-dev)
 *   - ANTHROPIC_API_KEY env var
 *   - GUZZLE_DEMO_URL env var (any http:// or https:// URL)
 *
 * Usage:
 *   ANTHROPIC_API_KEY=sk-... GUZZLE_DEMO_URL=https://httpbin.org/status/200 \
 *     php -d extension=openswoole demos/athena/02-guzzle-sdk-coexistence/demo.php
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Athena\Activity\Config as ActivityConfig;
use Phalanx\Athena\Athena;
use Phalanx\Athena\AthenaBundle;
use Phalanx\Athena\Router\SingleProviderRouter;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Anthropic\Provider as AnthropicProvider;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use Phalanx\Panoply\Transport\Sync\Transport as SyncTransport;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskScope;
use Phalanx\Task\Task;

// Aegis kernel requires OpenSwoole\Table; guard before boot.
if (!extension_loaded('openswoole')) {
    return DemoReport::demo(
        'Athena Guzzle SDK Coexistence',
        static function (DemoReport $report): void {
            $report->cannotRun(
                'openswoole extension required',
                'Run with: ANTHROPIC_API_KEY=sk-... GUZZLE_DEMO_URL=https://httpbin.org/status/200 php -d extension=openswoole demos/athena/02-guzzle-sdk-coexistence/demo.php',
            );
        },
    );
}

// The outer closure receives $context from symfony/runtime before DemoApp::boot
// creates the Aegis kernel. We read the API key here so we can build the
// AthenaBundle (which needs a concrete provider/router) before boot.
return static function (array $context): Closure {
    $apiKey    = (string) ($context['ANTHROPIC_API_KEY'] ?? '');
    $guzzleUrl = (string) ($context['GUZZLE_DEMO_URL'] ?? '');

    // Preflight: if any required input is missing, return a cannotRun closure
    // immediately so we never boot the Aegis kernel unnecessarily.
    if (!class_exists(\GuzzleHttp\Client::class)) {
        $inner = DemoReport::demo(
            'Athena Guzzle SDK Coexistence',
            static function (DemoReport $report): void {
                $report->cannotRun(
                    'guzzlehttp/guzzle is not installed',
                    'run `composer install` from the monorepo root',
                );
            },
        );
        return ($inner)([]);
    }

    if ($apiKey === '') {
        $inner = DemoReport::demo(
            'Athena Guzzle SDK Coexistence',
            static function (DemoReport $report): void {
                $report->cannotRun(
                    'a live Anthropic API key was not provided',
                    'rerun with ANTHROPIC_API_KEY=sk-... GUZZLE_DEMO_URL=https://httpbin.org/status/200 plus openswoole',
                );
            },
        );
        return ($inner)([]);
    }

    if ($guzzleUrl === '') {
        $inner = DemoReport::demo(
            'Athena Guzzle SDK Coexistence',
            static function (DemoReport $report): void {
                $report->cannotRun(
                    'a Guzzle target URL was not provided',
                    'rerun with GUZZLE_DEMO_URL=https://httpbin.org/status/200 plus the Anthropic key',
                );
            },
        );
        return ($inner)([]);
    }

    $scheme = parse_url($guzzleUrl, PHP_URL_SCHEME);
    $host   = parse_url($guzzleUrl, PHP_URL_HOST);

    if (!in_array($scheme, ['http', 'https'], true)) {
        $inner = DemoReport::demo(
            'Athena Guzzle SDK Coexistence',
            static function (DemoReport $report): void {
                $report->cannotRun(
                    'GUZZLE_DEMO_URL must start with http:// or https://',
                    'rerun with GUZZLE_DEMO_URL=https://httpbin.org/status/200',
                );
            },
        );
        return ($inner)([]);
    }

    if ($scheme === 'https' && is_string($host) && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        $inner = DemoReport::demo(
            'Athena Guzzle SDK Coexistence',
            static function (DemoReport $report): void {
                $report->cannotRun(
                    'GUZZLE_DEMO_URL points HTTPS at a local address',
                    'use http://localhost:<port> for local servers or a real HTTPS endpoint',
                );
            },
        );
        return ($inner)([]);
    }

    // All preflight passed. Build the Anthropic provider and AthenaBundle now
    // that we have a verified API key. DemoApp::boot captures the bundle.
    $anthropicProvider = new AnthropicProvider(
        transport: new SyncTransport(),
        apiKey: $apiKey,
        model: Model::of(
            name: 'claude-haiku-4-5',
            modelId: 'claude-haiku-4-5-20251001',
            aliases: [],
            capabilities: Capabilities::of(Capability::Streaming),
        ),
    );

    $bundle = new AthenaBundle(new SingleProviderRouter($anthropicProvider));

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
            get => ProviderNeeds::new()->prefer(Preference::Hosted);
        }
        public Capabilities $capabilities {
            get => Capabilities::of(Capability::Streaming);
        }
        public TransportNeeds $transport {
            get => TransportNeeds::new()->streaming();
        }
    };

    $capturedUrl = $guzzleUrl;

    // DemoApp::boot returns the inner Closure directly; symfony/runtime
    // invokes our outer static fn and calls the returned Closure.
    $bootClosure = DemoApp::boot(
        'Athena Guzzle SDK Coexistence',
        static function (DemoApp $app, DemoReport $report) use ($agent, $capturedUrl): void {
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
