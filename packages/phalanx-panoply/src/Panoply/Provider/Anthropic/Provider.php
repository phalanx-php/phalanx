<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\Anthropic;

use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider as ProviderContract;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Stream;
use Phalanx\Panoply\Transport as TransportContract;

/**
 * Anthropic Messages API provider. Translates Anthropic's SSE wire format
 * into the canonical panoply {@see Cue} stream.
 *
 * Composes a {@see TransportContract} (usually {@see \Phalanx\Panoply\Transport\Sync\Transport}
 * or phalanx-iris's async transport), a resolved {@see Model} config, and
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
        private(set) Capabilities $capabilities,
        private(set) string $baseUrl = 'https://api.anthropic.com',
    ) {
    }

    public function perform(Invocation $invocation, Runtime $runtime): Stream
    {
        $request   = RequestBuilder::build($invocation, $this->model, $this->apiKey, $this->baseUrl);
        $transport = $this->transport;
        $mapper    = new CueMapper($invocation);

        return new Stream(static function () use ($transport, $request, $runtime, $mapper): \Generator {
            $parser = new SseParser();

            foreach ($transport->stream($request, $runtime) as $chunk) {
                foreach ($parser->feed($chunk) as $event) {
                    yield from $mapper->translate($event);
                }
            }

            foreach ($parser->flush() as $event) {
                yield from $mapper->translate($event);
            }
        });
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }
}
