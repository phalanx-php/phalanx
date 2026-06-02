<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\HuggingFace;

use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider as ProviderContract;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Provider\OpenAI\ChatCueMapper;
use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Sse\Parser;
use Phalanx\Panoply\Stream;
use Phalanx\Panoply\Transport as TransportContract;

/**
 * Hugging Face Inference API provider. The wire format is byte-for-byte
 * OpenAI Chat Completions-compatible — this provider composes
 * {@see ChatCueMapper} directly, passing `providerId: 'huggingface'` so
 * emitted {@see \Phalanx\Panoply\Cue\Provider\Resolved} cues carry the
 * correct provider identity.
 *
 * This enables zero duplication of the mapper state machine while preserving
 * correct provenance in the Cue stream.
 *
 * If HuggingFace's wire format ever diverges from OpenAI Chat Completions,
 * fork {@see ChatCueMapper} rather than extending it — the `providerId`
 * constructor argument is a provenance hook, not an extension point.
 *
 * Composes a {@see TransportContract}, a resolved {@see Model} config, and
 * an API key (HuggingFace token). The provider itself is stateless — all
 * state lives in the {@see ChatCueMapper} allocated per call.
 *
 * Final — sealed provider contract; extension would alter the mapping
 * invariants tests depend on.
 */
final class InferenceProvider implements ProviderContract
{
    /**
     * @param array<string, string> $defaultHeaders
     */
    public function __construct(
        private(set) TransportContract $transport,
        private(set) string $apiKey,
        private(set) Model $model,
        private(set) string $baseUrl = 'https://api-inference.huggingface.co',
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
        $mapper = new ChatCueMapper(invocation: $invocation, providerId: 'huggingface');

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
