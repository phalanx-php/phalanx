<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Provider\Anthropic;

use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Provider as ProviderContract;
use Phalanx\AiProviders\Provider\Config\Model;
use Phalanx\AiProviders\Runtime;
use Phalanx\AiProviders\Sse\Parser;
use Phalanx\AiProviders\Stream;
use Phalanx\AiProviders\Transport as TransportContract;

/**
 * Anthropic Messages API provider. Translates Anthropic's SSE wire format
 * into the canonical ai-providers {@see Cue} stream.
 *
 * Composes a {@see TransportContract} (usually {@see \Phalanx\AiProviders\Transport\Sync\Transport}
 * or phalanx-http-client's async transport), a resolved {@see Model} config, and
 * an API key. The provider itself is stateless — all state lives in the
 * {@see CueMapper} allocated per call.
 *
 * Final — sealed provider contract; extension would alter the SSE mapping
 * invariants tests depend on.
 */
final class Provider implements ProviderContract
{
    public function __construct(
        private(set) TransportContract $transport,
        private(set) string $apiKey,
        private(set) Model $model,
        private(set) string $baseUrl = 'https://api.anthropic.com',
        private(set) MessagesOptions $messagesOptions = new MessagesOptions(),
    ) {
    }

    public function perform(Invocation $invocation, Runtime $runtime): Stream
    {
        $request = RequestBuilder::build(
            $invocation,
            $this->model,
            $this->apiKey,
            $this->baseUrl,
            $this->messagesOptions,
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
