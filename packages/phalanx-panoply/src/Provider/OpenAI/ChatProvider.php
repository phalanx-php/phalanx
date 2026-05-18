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
 * OpenAI Chat Completions provider. Translates OpenAI's typeless SSE wire
 * format (Chat Completions) into the canonical panoply {@see Cue} stream.
 *
 * This provider also serves as the wire translator for every OpenAI-compatible
 * endpoint (Together, Groq, OpenRouter, LMStudio, llamacpp). Pass the
 * target `base_url` from the loaded provider config to redirect requests
 * to the correct host.
 *
 * Composes a {@see TransportContract}, a resolved {@see Model} config, and
 * an API key. The provider itself is stateless — all state lives in the
 * {@see ChatCueMapper} allocated per call.
 *
 * Final — sealed provider contract; extension would alter the SSE mapping
 * invariants tests depend on.
 */
final class ChatProvider implements ProviderContract
{
    /**
     * @param array<string, string> $defaultHeaders
     */
    public function __construct(
        private(set) TransportContract $transport,
        private(set) string $apiKey,
        private(set) Model $model,
        private(set) string $baseUrl = 'https://api.openai.com',
        private(set) ChatOptions $chatOptions = new ChatOptions(),
        private(set) array $defaultHeaders = [],
    ) {
    }

    public function perform(Invocation $invocation, Runtime $runtime): Stream
    {
        $request = ChatRequestBuilder::build(
            $invocation,
            $this->model,
            $this->apiKey,
            $this->baseUrl,
            $this->chatOptions,
            $this->defaultHeaders,
        );
        $transport = $this->transport;
        $mapper = new ChatCueMapper($invocation);

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
