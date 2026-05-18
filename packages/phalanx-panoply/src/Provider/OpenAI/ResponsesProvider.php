<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\OpenAI;

use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider as ProviderContract;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Sse\Parser;
use Phalanx\Panoply\Stream;
use Phalanx\Panoply\Transport as TransportContract;

/**
 * OpenAI Responses API provider. Translates OpenAI's named-event SSE wire
 * format (Responses API) into the canonical panoply {@see Cue} stream.
 *
 * Unlike the Chat Completions format (typeless events), the Responses API
 * uses an explicit `event:` field on each SSE chunk, making it structurally
 * similar to Anthropic's wire format. The {@see ResponsesCueMapper} handles
 * the event-type-to-cue dispatch.
 *
 * Composes a {@see TransportContract}, a resolved {@see Model} config, and
 * an API key. The provider itself is stateless — all state lives in the
 * {@see ResponsesCueMapper} allocated per call.
 *
 * Final — sealed provider contract; extension would alter the SSE mapping
 * invariants tests depend on.
 */
final class ResponsesProvider implements ProviderContract
{
    /**
     * @param array<string, string> $defaultHeaders
     */
    public function __construct(
        private(set) TransportContract $transport,
        private(set) string $apiKey,
        private(set) Model $model,
        private(set) string $baseUrl = 'https://api.openai.com',
        private(set) ResponsesOptions $responsesOptions = new ResponsesOptions(),
        private(set) array $defaultHeaders = [],
    ) {
    }

    public function perform(Invocation $invocation, Runtime $runtime): Stream
    {
        $request = ResponsesRequestBuilder::build(
            $invocation,
            $this->model,
            $this->apiKey,
            $this->baseUrl,
            $this->responsesOptions,
            $this->defaultHeaders,
        );
        $transport = $this->transport;
        $mapper    = new ResponsesCueMapper($invocation);

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
