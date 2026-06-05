<?php

declare(strict_types=1);

namespace Phalanx\Demos\Kit;

use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Provider;
use Phalanx\AiProviders\Provider\Config\Model;
use Phalanx\AiProviders\Provider\Fake\Provider as FakeProvider;
use Phalanx\AiProviders\Provider\Ollama\ChatOptions;
use Phalanx\AiProviders\Provider\Ollama\ChatProvider as OllamaChatProvider;
use Phalanx\AiProviders\Transport\Sync\Transport as SyncTransport;

/**
 * Demo-side helper that resolves a {@see Provider} appropriate for the
 * current environment. Probes Ollama at the given base URL with a short
 * timeout; falls back to a deterministic {@see FakeProvider} script when
 * Ollama is unreachable or the requested model is not installed.
 *
 * The returned {@see ProviderChoice} carries the resolved provider AND a
 * human-readable description so demo scripts can surface which path is
 * active via {@see DemoReport::note()}.
 *
 * Final — no extension points; the choice contract is closed.
 */
final class DemoProvider
{
    /** @internal */
    private const string REACHABLE_NO_SERVICE = 'no_service';
    /** @internal */
    private const string REACHABLE_MODEL_MISSING = 'model_missing';
    /** @internal */
    private const string REACHABLE_OK = 'ok';

    public const string OLLAMA_BASE = 'http://localhost:11434';

    private function __construct()
    {
    }

    /**
     * Resolve the best available provider for demo usage.
     *
     * Probes Ollama at $baseUrl for the exact $model. If Ollama is
     * unreachable the returned choice uses the FakeProvider. If Ollama
     * is running but the model is not installed, the choice also falls back
     * to the FakeProvider with a descriptive message — the demo never runs
     * with a substitute model that produces garbage output.
     *
     * @param list<\Phalanx\AiProviders\Cue> $fakeScript Cue sequence to replay when Ollama is down.
     */
    public static function ollamaOrFake(
        array $fakeScript,
        string $model,
        string $baseUrl = self::OLLAMA_BASE,
    ): ProviderChoice {
        $reachable = self::probeOllama($baseUrl, $model);

        if ($reachable === self::REACHABLE_NO_SERVICE) {
            return new ProviderChoice(
                provider: self::makeFake($fakeScript),
                usingLiveProvider: false,
                description: sprintf('Fake provider (Ollama unreachable at %s)', $baseUrl),
            );
        }

        if ($reachable === self::REACHABLE_MODEL_MISSING) {
            return new ProviderChoice(
                provider: self::makeFake($fakeScript),
                usingLiveProvider: false,
                description: sprintf(
                    'Fake provider (Ollama running at %s but model "%s" not installed; try: ollama pull %s)',
                    $baseUrl,
                    $model,
                    $model,
                ),
            );
        }

        return new ProviderChoice(
            provider: self::makeOllama($model, $baseUrl),
            usingLiveProvider: true,
            description: sprintf('Ollama %s at %s', $model, $baseUrl),
        );
    }

    /**
     * Return a ProviderChoice that always uses the FakeProvider, skipping
     * any Ollama probe. Useful when the caller has already determined that
     * live provider access should be bypassed (e.g. OLLAMA_ENABLED=0).
     *
     * @param list<\Phalanx\AiProviders\Cue> $fakeScript
     */
    public static function fakeOnly(array $fakeScript): ProviderChoice
    {
        return new ProviderChoice(
            provider: self::makeFake($fakeScript),
            usingLiveProvider: false,
            description: 'Fake provider (live provider disabled)',
        );
    }

    /**
     * Probe Ollama's /api/tags endpoint. Returns one of three internal
     * state strings:
     *   REACHABLE_NO_SERVICE  — connection refused, timeout, or non-200
     *   REACHABLE_MODEL_MISSING — Ollama is up but $model is not installed
     *   REACHABLE_OK          — Ollama is up and $model is available
     */
    private static function probeOllama(string $baseUrl, string $model): string
    {
        $handle = curl_init($baseUrl . '/api/tags');
        if ($handle === false) {
            return self::REACHABLE_NO_SERVICE;
        }

        try {
            curl_setopt_array($handle, [
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            $body   = (string) curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

            if ($status !== 200 || $body === '') {
                return self::REACHABLE_NO_SERVICE;
            }

            /** @var array{models?: list<array{name: string}>}|null $data */
            $data = json_decode($body, true);
            if (!is_array($data) || !isset($data['models']) || $data['models'] === []) {
                return self::REACHABLE_MODEL_MISSING;
            }

            $names = array_map(static fn (array $m): string => (string) $m['name'], $data['models']);

            foreach ($names as $name) {
                if ($name === $model || str_starts_with($name, $model . ':')) {
                    return self::REACHABLE_OK;
                }
            }

            return self::REACHABLE_MODEL_MISSING;
        } catch (\Throwable) {
            return self::REACHABLE_NO_SERVICE;
        } finally {
            curl_close($handle);
        }
    }

    private static function makeOllama(string $model, string $baseUrl): Provider
    {
        return new OllamaChatProvider(
            transport: new SyncTransport(),
            model: Model::of(
                name: $model,
                modelId: $model,
                aliases: [],
                capabilities: Capabilities::of(Capability::Streaming),
            ),
            baseUrl: $baseUrl,
            chatOptions: new ChatOptions(),
        );
    }

    /**
     * @param list<\Phalanx\AiProviders\Cue> $script
     */
    private static function makeFake(array $script): Provider
    {
        return new FakeProvider($script, Capabilities::empty());
    }
}
