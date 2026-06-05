<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Provider\Gemini;

use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Provider as ProviderContract;
use Phalanx\AiProviders\Provider\Config\Model;
use Phalanx\AiProviders\Runtime;
use Phalanx\AiProviders\Sse\Parser;
use Phalanx\AiProviders\Stream;
use Phalanx\AiProviders\Transport as TransportContract;

/**
 * Google Gemini Generative Language API provider. Translates Gemini's
 * typeless SSE wire format into the canonical ai-providers {@see Cue} stream.
 *
 * Composes a {@see TransportContract}, a resolved {@see Model} config, and
 * an API key. The provider itself is stateless — all state lives in the
 * {@see CueMapper} allocated per call. The API key travels in the request URL
 * query string, not an Authorization header (Gemini Generative Language
 * convention).
 *
 * Final — sealed provider contract; extension would alter the SSE mapping
 * invariants tests depend on.
 */
final class Provider implements ProviderContract
{
    /**
     * @param array<string, string> $defaultHeaders
     */
    public function __construct(
        private(set) TransportContract $transport,
        private(set) string $apiKey,
        private(set) Model $model,
        private(set) string $baseUrl = 'https://generativelanguage.googleapis.com',
        private(set) Options $options = new Options(),
        private(set) array $defaultHeaders = [],
    ) {
    }

    public function perform(Invocation $invocation, Runtime $runtime): Stream
    {
        $request = RequestBuilder::build(
            $invocation,
            $this->model,
            $this->apiKey,
            $this->baseUrl,
            $this->options,
            $this->defaultHeaders,
        );
        $transport = $this->transport;
        $mapper = new CueMapper($invocation);

        return new Stream(static function () use ($transport, $request, $runtime, $mapper): \Generator {
            $parser = new Parser();

            foreach ($transport->stream($request, $runtime) as $chunk) {
                foreach ($parser->feed($chunk) as $event) {
                    yield from $mapper->translate($event);
                }
            }

            foreach ($parser->flush() as $event) {
                yield from $mapper->translate($event);
            }

            yield from $mapper->complete();
        });
    }

    public function capabilities(): Capabilities
    {
        return $this->model->capabilities;
    }
}
